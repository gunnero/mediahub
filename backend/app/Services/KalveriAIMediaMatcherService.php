<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class KalveriAIMediaMatcherService
{
    private const LOCAL_CONFIDENCE_THRESHOLD = 0.86;

    public function __construct(
        private readonly MediaEventService $mediaEvents,
        private readonly MediaMetadataService $metadata,
        private readonly KalveriAIClient $kalveriAI,
        private readonly SafeAIMatchingPayloadService $payloads,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function matchProviderItem(User $user, PlaybackSourceItem $item): array
    {
        $this->assertOwnedItem($user, $item);
        $item->loadMissing(['source', 'mediaLink']);

        $this->record($user, MediaEventType::AIMatchRequested, $item, [
            'title' => $item->title,
            'kind' => $item->kind,
            'playback_source_item_id' => $item->id,
        ], MediaEventSource::AI);

        $candidates = $this->providerCandidates($user, $item);
        $localSuggestion = $this->bestLocalProviderSuggestion($item, $candidates);
        $suggestion = $localSuggestion;

        if (($localSuggestion['confidence'] ?? 0.0) < self::LOCAL_CONFIDENCE_THRESHOLD) {
            $kalveriAIResult = $this->kalveriAI->matchProviderItem($this->providerPayload($item, $candidates));
            $kalveriAISuggestion = $this->normalizeProviderSuggestion($kalveriAIResult, $candidates);

            if (($kalveriAISuggestion['confidence'] ?? 0.0) > ($localSuggestion['confidence'] ?? 0.0)) {
                $suggestion = $kalveriAISuggestion;
            } elseif (($localSuggestion['confidence'] ?? 0.0) <= 0.0) {
                $suggestion = $this->emptySuggestion('provider_item', $kalveriAIResult['status'] ?? 'no_match', $kalveriAIResult['error'] ?? 'no_confident_match');
            }
        }

        $suggestion = $this->finalizeProviderSuggestion($suggestion, $item, $candidates);
        $this->storeSuggestion($item, 'ai_match_suggestion', $suggestion);

        if (($suggestion['candidateId'] ?? null) !== null) {
            $this->record($user, MediaEventType::AIMatchSuggested, $item, [
                'title' => $item->title,
                'kind' => $item->kind,
                'media_type' => $suggestion['mediaType'] ?? null,
                'candidate_id' => $suggestion['candidateId'] ?? null,
                'confidence' => $suggestion['confidence'] ?? null,
                'requires_confirmation' => true,
            ], MediaEventSource::AI);
        }

        return [
            'suggestion' => $suggestion,
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function matchMetadataReviewEpisode(User $user, Episode $episode): array
    {
        $episode = Episode::forUser($user)->with('show')->findOrFail($episode->id);

        $this->record($user, MediaEventType::AIMatchRequested, $episode, [
            'media_type' => 'episode',
            'show_id' => $episode->show_id,
            'season_number' => $episode->season_number,
            'episode_number' => $episode->episode_number,
        ], MediaEventSource::AI);

        $result = $this->kalveriAI->matchMetadataReviewEpisode($this->metadataReviewPayload($episode));
        $suggestion = $this->normalizeEpisodeReviewSuggestion($result);

        $this->storeSuggestion($episode, 'ai_review_suggestion', $suggestion);

        if (($suggestion['tmdbSeason'] ?? null) && ($suggestion['tmdbEpisode'] ?? null)) {
            $this->record($user, MediaEventType::AIMatchSuggested, $episode, [
                'media_type' => 'episode',
                'show_id' => $episode->show_id,
                'season_number' => $episode->season_number,
                'episode_number' => $episode->episode_number,
                'tmdb_season' => $suggestion['tmdbSeason'],
                'tmdb_episode' => $suggestion['tmdbEpisode'],
                'confidence' => $suggestion['confidence'],
                'requires_confirmation' => true,
            ], MediaEventSource::AI);
        }

        return ['suggestion' => $suggestion];
    }

    public function rejectProviderSuggestion(User $user, PlaybackSourceItem $item): void
    {
        $this->assertOwnedItem($user, $item);

        $metadata = $item->metadata ?? [];
        $metadata['ai_match_suggestion'] = [
            ...($metadata['ai_match_suggestion'] ?? []),
            'status' => 'rejected',
            'rejected_at' => now()->toIso8601String(),
        ];

        $item->forceFill(['metadata' => $this->payloads->sanitize($metadata)])->save();

        $this->record($user, MediaEventType::AIMatchRejected, $item, [
            'title' => $item->title,
            'kind' => $item->kind,
            'playback_source_item_id' => $item->id,
        ], MediaEventSource::AI);
    }

    /**
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function applyReviewMatch(User $user, Episode $episode, int $season, int $episodeNumber): array
    {
        $episode = Episode::forUser($user)->findOrFail($episode->id);
        $summary = $this->metadata->matchEpisode($episode, $season, $episodeNumber);

        if ($summary['enriched'] === 1) {
            $this->record($user, MediaEventType::AIMatchConfirmed, $episode, [
                'media_type' => 'episode',
                'show_id' => $episode->show_id,
                'tmdb_season' => $season,
                'tmdb_episode' => $episodeNumber,
            ], MediaEventSource::AI);
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function providerCandidates(User $user, PlaybackSourceItem $item): array
    {
        $title = trim($item->title);
        $kind = $item->kind ?: 'movie';
        $parsed = $this->parseEpisodeNumbers($title);
        $terms = $this->candidateSearchTerms($title);

        $candidates = [];

        if ($kind === 'movie') {
            $candidates = array_merge($candidates, Movie::forUser($user)
                ->when($terms !== [], fn (Builder $query) => $query->where(function (Builder $nested) use ($terms): void {
                    foreach ($terms as $term) {
                        $nested->orWhere('title', 'like', '%'.$term.'%');
                    }
                }))
                ->orderBy('title')
                ->limit(8)
                ->get()
                ->map(fn (Movie $movie): array => [
                    'type' => 'movie',
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'originalTitle' => $movie->original_title,
                    'year' => $movie->release_date?->year,
                    'tmdbId' => $movie->tmdb_id,
                ])
                ->all());
        }

        if ($kind === 'show') {
            $candidates = array_merge($candidates, Show::forUser($user)
                ->when($terms !== [], fn (Builder $query) => $query->where(function (Builder $nested) use ($terms): void {
                    foreach ($terms as $term) {
                        $nested->orWhere('title', 'like', '%'.$term.'%');
                    }
                }))
                ->orderBy('title')
                ->limit(8)
                ->get()
                ->map(fn (Show $show): array => [
                    'type' => 'show',
                    'id' => $show->id,
                    'title' => $show->title,
                    'originalTitle' => $show->original_title,
                    'year' => $show->first_air_date?->year,
                    'tmdbId' => $show->tmdb_id,
                ])
                ->all());
        }

        if ($kind === 'episode') {
            $candidates = array_merge($candidates, Episode::forUser($user)
                ->with(['show' => fn (Builder $query) => $query->forUser($user)])
                ->whereHas('show', fn (Builder $query) => $query->forUser($user))
                ->when($parsed['season'] ?? null, fn (Builder $query) => $query->where('season_number', $parsed['season']))
                ->when($parsed['episode'] ?? null, fn (Builder $query) => $query->where('episode_number', $parsed['episode']))
                ->when($terms !== [], fn (Builder $query) => $query->where(function (Builder $nested) use ($terms, $user): void {
                    $nested
                        ->where(function (Builder $titleQuery) use ($terms): void {
                            foreach ($terms as $term) {
                                $titleQuery->orWhere('title', 'like', '%'.$term.'%');
                            }
                        })
                        ->orWhereHas('show', fn (Builder $showQuery) => $showQuery->forUser($user)->where(function (Builder $showTitleQuery) use ($terms): void {
                            foreach ($terms as $term) {
                                $showTitleQuery->orWhere('title', 'like', '%'.$term.'%');
                            }
                        }));
                }))
                ->orderBy('show_id')
                ->orderBy('season_number')
                ->orderBy('episode_number')
                ->limit(12)
                ->get()
                ->map(fn (Episode $episode): array => [
                    'type' => 'episode',
                    'id' => $episode->id,
                    'title' => $episode->title ?: $episode->show?->title,
                    'showTitle' => $episode->show?->title,
                    'seasonNumber' => $episode->season_number,
                    'episodeNumber' => $episode->episode_number,
                    'tmdbId' => $episode->tmdb_id,
                    'showTmdbId' => $episode->show?->tmdb_id,
                ])
                ->all());
        }

        return array_slice($candidates, 0, 15);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function bestLocalProviderSuggestion(PlaybackSourceItem $item, array $candidates): array
    {
        $needle = $this->normalize($item->title);
        $best = $this->emptySuggestion('provider_item', 'no_match', 'No local candidate found.');

        foreach ($candidates as $candidate) {
            $candidateTitle = $candidate['type'] === 'episode'
                ? trim(($candidate['showTitle'] ?? '').' '.$this->episodeCode($candidate).' '.($candidate['title'] ?? ''))
                : (string) ($candidate['title'] ?? '');
            $candidateNeedle = $this->normalize($candidateTitle);

            similar_text($needle, $candidateNeedle, $score);
            $confidence = round(min(1, max(0, $score / 100)), 2);

            if ($needle !== '' && $needle === $this->normalize((string) ($candidate['title'] ?? ''))) {
                $confidence = 0.96;
            }

            if ($confidence > ($best['confidence'] ?? 0)) {
                $best = [
                    'status' => 'suggested',
                    'source' => 'deterministic',
                    'mediaType' => $candidate['type'],
                    'candidateId' => $candidate['id'],
                    'candidate' => $candidate,
                    'confidence' => $confidence,
                    'reason' => 'Local title similarity.',
                    'requiresConfirmation' => true,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function providerPayload(PlaybackSourceItem $item, array $candidates): array
    {
        $parsed = $this->parseEpisodeNumbers($item->title);

        return $this->payloads->sanitize([
            'source_item' => [
                'id' => $item->id,
                'normalized_title' => $this->normalize($item->title),
                'original_title' => $item->title,
                'media_type_guess' => $item->kind,
                'year' => $this->parseYear($item->title),
                'season_number' => $parsed['season'] ?? null,
                'episode_number' => $parsed['episode'] ?? null,
            ],
            'candidates' => $candidates,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataReviewPayload(Episode $episode): array
    {
        return $this->payloads->sanitize([
            'episode' => [
                'id' => $episode->id,
                'normalized_title' => $this->normalize((string) $episode->title),
                'original_title' => $episode->title,
                'media_type_guess' => 'episode',
                'season_number' => $episode->season_number,
                'episode_number' => $episode->episode_number,
            ],
            'show' => [
                'id' => $episode->show_id,
                'normalized_title' => $this->normalize((string) $episode->show?->title),
                'original_title' => $episode->show?->title,
                'tmdb_id' => $episode->show?->tmdb_id,
                'year' => $episode->show?->first_air_date?->year,
            ],
            'current_failure' => [
                'reason' => $episode->last_metadata_failure_reason,
                'count' => $episode->metadata_failure_count,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function normalizeProviderSuggestion(array $result, array $candidates): array
    {
        $raw = is_array($result['suggestion'] ?? null) ? $result['suggestion'] : $result;
        $type = (string) ($raw['media_type'] ?? $raw['type'] ?? '');
        $candidateId = (int) ($raw['candidate_id'] ?? $raw['id'] ?? 0);
        $candidate = $this->candidateById($candidates, $type, $candidateId);

        if (! $candidate) {
            return $this->emptySuggestion('provider_item', $result['status'] ?? 'no_match', 'Kalveri AI did not return a same-user candidate.');
        }

        return [
            'status' => 'suggested',
            'source' => 'kalveri_ai',
            'mediaType' => $candidate['type'],
            'candidateId' => $candidate['id'],
            'candidate' => $candidate,
            'confidence' => $this->confidence($raw['confidence'] ?? 0),
            'reason' => (string) ($raw['reason'] ?? 'Kalveri AI suggested this same-user candidate.'),
            'requiresConfirmation' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEpisodeReviewSuggestion(array $result): array
    {
        $raw = is_array($result['suggestion'] ?? null) ? $result['suggestion'] : $result;
        $season = (int) ($raw['tmdb_season'] ?? $raw['season'] ?? 0);
        $episode = (int) ($raw['tmdb_episode'] ?? $raw['episode'] ?? 0);

        if ($season < 1 || $episode < 1) {
            return [
                'status' => $result['status'] ?? 'no_match',
                'source' => 'kalveri_ai',
                'tmdbSeason' => null,
                'tmdbEpisode' => null,
                'confidence' => 0.0,
                'reason' => (string) ($result['error'] ?? 'Kalveri AI did not suggest a positive TMDB season and episode.'),
                'requiresConfirmation' => true,
            ];
        }

        return [
            'status' => 'suggested',
            'source' => 'kalveri_ai',
            'tmdbSeason' => $season,
            'tmdbEpisode' => $episode,
            'confidence' => $this->confidence($raw['confidence'] ?? 0),
            'reason' => (string) ($raw['reason'] ?? 'Kalveri AI suggested this corrected TMDB mapping.'),
            'requiresConfirmation' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function finalizeProviderSuggestion(array $suggestion, PlaybackSourceItem $item, array $candidates): array
    {
        $suggestion['sourceItemId'] = $item->id;
        $suggestion['requiresConfirmation'] = true;
        $suggestion['candidate'] = isset($suggestion['mediaType'], $suggestion['candidateId'])
            ? $this->candidateById($candidates, (string) $suggestion['mediaType'], (int) $suggestion['candidateId'])
            : null;

        if (! $suggestion['candidate']) {
            $suggestion['status'] = $suggestion['status'] ?? 'no_match';
            $suggestion['candidateId'] = null;
            $suggestion['mediaType'] = null;
        }

        return $this->payloads->sanitize($suggestion);
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    private function storeSuggestion(PlaybackSourceItem|Episode $model, string $key, array $suggestion): void
    {
        $metadata = $model->metadata ?? [];
        $metadata[$key] = [
            ...$this->payloads->sanitize($suggestion),
            'suggested_at' => now()->toIso8601String(),
        ];

        $model->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function candidateById(array $candidates, string $type, int $id): ?array
    {
        foreach ($candidates as $candidate) {
            if (($candidate['type'] ?? null) === $type && (int) ($candidate['id'] ?? 0) === $id) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySuggestion(string $scope, string $status, string $reason): array
    {
        return [
            'status' => $status,
            'source' => 'none',
            'scope' => $scope,
            'mediaType' => null,
            'candidateId' => null,
            'candidate' => null,
            'confidence' => 0.0,
            'reason' => $reason,
            'requiresConfirmation' => true,
        ];
    }

    private function assertOwnedItem(User $user, PlaybackSourceItem $item): void
    {
        $item->loadMissing('source');

        if ($item->user_id !== $user->id || $item->source?->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }

    private function normalize(string $value): string
    {
        return (string) str($value)
            ->lower()
            ->replaceMatches('/\b(1080p|720p|2160p|x264|x265|h264|h265|bluray|webrip|web-dl|dvdrip)\b/i', ' ')
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish();
    }

    private function searchableTitle(string $value): string
    {
        return (string) Str::of($value)
            ->replaceMatches('/s\d{1,2}e\d{1,3}/i', ' ')
            ->replaceMatches('/\d{1,2}x\d{1,3}/i', ' ')
            ->replaceMatches('/\b(1080p|720p|2160p|x264|x265|h264|h265|bluray|webrip|web-dl|dvdrip)\b/i', ' ')
            ->squish();
    }

    /**
     * @return list<string>
     */
    private function candidateSearchTerms(string $value): array
    {
        $searchable = $this->searchableTitle($value);
        $tokens = str($searchable)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish()
            ->explode(' ')
            ->filter(fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, ['the', 'and', 'with', 'from'], true))
            ->take(3)
            ->values()
            ->all();

        $terms = array_filter([$searchable, ...$tokens], fn (string $term): bool => trim($term) !== '');

        return array_values(array_unique(array_map(
            fn (string $term): string => str_replace(['%', '_'], ['\%', '\_'], trim($term)),
            $terms,
        )));
    }

    /**
     * @return array{season:int|null,episode:int|null}
     */
    private function parseEpisodeNumbers(string $title): array
    {
        if (preg_match('/s(?P<season>\d{1,2})e(?P<episode>\d{1,3})/i', $title, $match)) {
            return ['season' => (int) $match['season'], 'episode' => (int) $match['episode']];
        }

        if (preg_match('/(?P<season>\d{1,2})x(?P<episode>\d{1,3})/i', $title, $match)) {
            return ['season' => (int) $match['season'], 'episode' => (int) $match['episode']];
        }

        return ['season' => null, 'episode' => null];
    }

    private function parseYear(string $title): ?int
    {
        if (preg_match('/\b(19\d{2}|20\d{2})\b/', $title, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function episodeCode(array $candidate): string
    {
        return 'S'.($candidate['seasonNumber'] ?? '?').' E'.($candidate['episodeNumber'] ?? '?');
    }

    private function confidence(mixed $value): float
    {
        return round(min(1, max(0, (float) $value)), 2);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(User $user, MediaEventType $eventType, PlaybackSourceItem|Episode $subject, array $metadata, MediaEventSource $source): void
    {
        $this->mediaEvents->record($user, $eventType, $subject, $metadata, $source);
    }
}
