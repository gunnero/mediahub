<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Enums\MetadataReviewStatus;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

class MediaMetadataService
{
    public const EPISODE_FAILURE_THRESHOLD = 3;

    public function __construct(
        private readonly TMDBClientService $tmdb,
        private readonly MediaEventService $mediaEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function enrichMovie(Movie $movie, array $options = []): array
    {
        $options = $this->normalizeOptions($options);

        if (! $this->tmdb->enabled()) {
            return $this->summary(skipped: 1);
        }

        $summary = $this->emptySummary();
        $details = null;
        $match = null;

        if ($movie->tmdb_id) {
            $details = $this->tmdb->getMovie($movie->tmdb_id);
            $match = ['source' => 'tmdb', 'confidence' => 1.0, 'method' => 'stored_tmdb_id'];
        } else {
            $summary['searched']++;
            $search = $this->tmdb->searchMovie($movie->title, $this->yearFromDate($movie->release_date));

            if ($search === null) {
                return $this->add($summary, $this->summary(failed: 1));
            }

            $result = $this->bestSearchResult($movie->title, $search['results'] ?? [], 'title');

            if (! $result) {
                return $this->add($summary, $this->summary(skipped: 1));
            }

            $confidence = $this->confidence($movie->title, (string) ($result['title'] ?? ''));

            if ($confidence < $options['min_confidence']) {
                return $this->add($summary, $this->summary(skipped: 1));
            }

            $match = [
                'source' => 'tmdb',
                'confidence' => $confidence,
                'method' => 'title_search',
            ];
            $details = $this->tmdb->getMovie((int) $result['id']);
        }

        if (! $details) {
            return $this->add($summary, $this->summary(failed: 1));
        }

        $this->applyMovieDetails($movie, $details, $match);
        $this->mediaEvents->record($movie->user, MediaEventType::MetadataEnriched, $movie, [
            'title' => $movie->title,
            'media_type' => 'movie',
            'tmdb_id' => $movie->tmdb_id,
            'match' => $match,
        ], MediaEventSource::Metadata);

        return $this->add($summary, $this->summary(matched: 1, enriched: 1));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function enrichShow(Show $show, bool $enrichEpisodes = false, array $options = []): array
    {
        $options = $this->normalizeOptions($options);

        if (! $this->tmdb->enabled()) {
            return $this->summary(skipped: 1);
        }

        $summary = $this->emptySummary();
        $details = null;
        $match = null;

        if ($show->tmdb_id) {
            $details = $this->tmdb->getShow($show->tmdb_id);
            $match = ['source' => 'tmdb', 'confidence' => 1.0, 'method' => 'stored_tmdb_id'];
        } else {
            $summary['searched']++;
            $search = $this->tmdb->searchShow($show->title, $this->yearFromDate($show->first_air_date));

            if ($search === null) {
                return $this->add($summary, $this->summary(failed: 1));
            }

            $result = $this->bestSearchResult($show->title, $search['results'] ?? [], 'name');

            if (! $result) {
                return $this->add($summary, $this->summary(skipped: 1));
            }

            $confidence = $this->confidence($show->title, (string) ($result['name'] ?? ''));

            if ($confidence < $options['min_confidence']) {
                return $this->add($summary, $this->summary(skipped: 1));
            }

            $match = [
                'source' => 'tmdb',
                'confidence' => $confidence,
                'method' => 'title_search',
            ];
            $details = $this->tmdb->getShow((int) $result['id']);
        }

        if (! $details) {
            return $this->add($summary, $this->summary(failed: 1));
        }

        $this->applyShowDetails($show, $details, $match);
        $this->mediaEvents->record($show->user, MediaEventType::MetadataEnriched, $show, [
            'title' => $show->title,
            'media_type' => 'show',
            'tmdb_id' => $show->tmdb_id,
            'match' => $match,
        ], MediaEventSource::Metadata);
        $summary = $this->add($summary, $this->summary(matched: 1, enriched: 1));

        if ($enrichEpisodes) {
            $summary = $this->add($summary, $this->enrichEpisodesForShow($show->refresh(), $options));
        }

        return $summary;
    }

    /**
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function matchMovie(Movie $movie, int $tmdbId): array
    {
        if (! $this->tmdb->enabled()) {
            return $this->summary(planned: 1, skipped: 1);
        }

        $details = $this->tmdb->getMovie($tmdbId);

        if (! $details) {
            return $this->summary(planned: 1, failed: 1);
        }

        $match = [
            'source' => 'tmdb',
            'confidence' => 1.0,
            'method' => 'manual',
        ];

        $this->applyMovieDetails($movie, $details, $match);
        $this->mediaEvents->record($movie->user, MediaEventType::MetadataEnriched, $movie, [
            'title' => $movie->title,
            'media_type' => 'movie',
            'tmdb_id' => $movie->tmdb_id,
            'match' => $match,
        ], MediaEventSource::Metadata);

        return $this->summary(planned: 1, matched: 1, enriched: 1);
    }

    /**
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function matchShow(Show $show, int $tmdbId): array
    {
        if (! $this->tmdb->enabled()) {
            return $this->summary(planned: 1, skipped: 1);
        }

        $details = $this->tmdb->getShow($tmdbId);

        if (! $details) {
            return $this->summary(planned: 1, failed: 1);
        }

        $match = [
            'source' => 'tmdb',
            'confidence' => 1.0,
            'method' => 'manual',
        ];

        $this->applyShowDetails($show, $details, $match);
        $this->mediaEvents->record($show->user, MediaEventType::MetadataEnriched, $show, [
            'title' => $show->title,
            'media_type' => 'show',
            'tmdb_id' => $show->tmdb_id,
            'match' => $match,
        ], MediaEventSource::Metadata);

        return $this->summary(planned: 1, matched: 1, enriched: 1);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function enrichUser(User $user, array $options = []): array
    {
        $options = $this->normalizeOptions($options);
        $summary = $this->emptySummary();
        $remaining = $options['limit'];

        if (in_array($options['type'], ['movies', 'all'], true) && $this->hasRemaining($remaining)) {
            $movies = $this->limitedQuery($this->missingFilter(Movie::forUser($user), $options), $remaining)
                ->orderBy('id')
                ->get();
            $summary['planned'] += $movies->count();
            $this->consumeRemaining($remaining, $movies->count());

            if (! $options['dry_run']) {
                $movies->each(function (Movie $movie) use (&$summary, $options): void {
                    $summary = $this->add($summary, $this->enrichMovie($movie, $options));
                    $this->sleepBetweenRecords($options);
                });
            }
        }

        if (in_array($options['type'], ['shows', 'all'], true) && $this->hasRemaining($remaining)) {
            $shows = $this->limitedQuery($this->missingFilter(Show::forUser($user), $options), $remaining)
                ->orderBy('id')
                ->get();
            $summary['planned'] += $shows->count();
            $this->consumeRemaining($remaining, $shows->count());

            if (! $options['dry_run']) {
                $shows->each(function (Show $show) use (&$summary, $options): void {
                    $summary = $this->add($summary, $this->enrichShow($show, enrichEpisodes: false, options: $options));
                    $this->sleepBetweenRecords($options);
                });
            }
        }

        if (in_array($options['type'], ['episodes', 'all'], true) && $this->hasRemaining($remaining)) {
            $episodes = $this->limitedQuery($this->episodeEligibilityFilter($this->missingFilter(Episode::forUser($user)->with('show'), $options), $options), $remaining)
                ->orderBy('show_id')
                ->orderBy('season_number')
                ->orderBy('episode_number')
                ->get();
            $summary['planned'] += $episodes->count();

            if (! $options['dry_run']) {
                $episodes->each(function (Episode $episode) use (&$summary, $options): void {
                    $summary = $this->add($summary, $this->enrichEpisode($episode));
                    $this->sleepBetweenRecords($options);
                });
            }
        }

        return $summary;
    }

    /**
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function enrichEpisode(Episode $episode): array
    {
        if (! $this->tmdb->enabled()) {
            return $this->summary(skipped: 1);
        }

        $episode->loadMissing('show');
        $show = $episode->show;

        if (! $show?->tmdb_id || (int) $episode->season_number <= 0 || (int) $episode->episode_number <= 0) {
            return $this->summary(skipped: 1);
        }

        $details = $this->tmdb->getEpisode((int) $show->tmdb_id, (int) $episode->season_number, (int) $episode->episode_number);

        if (! $details) {
            $this->trackEpisodeFailure($episode, $this->metadataFailureReason($this->tmdb->lastFailure()));

            return $this->summary(failed: 1);
        }

        $this->applyEpisodeDetails($episode, $details, [
            'source' => 'tmdb',
            'confidence' => 1.0,
            'method' => 'show_season_episode',
        ]);
        $this->mediaEvents->record($episode->user, MediaEventType::MetadataEnriched, $episode, [
            'title' => $episode->title,
            'media_type' => 'episode',
            'show_id' => $episode->show_id,
            'tmdb_id' => $episode->tmdb_id,
        ], MediaEventSource::Metadata);

        return $this->summary(matched: 1, enriched: 1);
    }

    /**
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function matchEpisode(Episode $episode, int $tmdbSeason, int $tmdbEpisode): array
    {
        if (! $this->tmdb->enabled()) {
            return $this->summary(planned: 1, skipped: 1);
        }

        $episode->loadMissing('show');
        $show = $episode->show;

        if (! $show?->tmdb_id || $tmdbSeason < 1 || $tmdbEpisode < 1) {
            return $this->summary(planned: 1, failed: 1);
        }

        $details = $this->tmdb->getEpisode((int) $show->tmdb_id, $tmdbSeason, $tmdbEpisode);

        if (! $details) {
            $this->trackEpisodeFailure($episode, $this->metadataFailureReason($this->tmdb->lastFailure()));

            return $this->summary(planned: 1, failed: 1);
        }

        $this->applyEpisodeDetails($episode, $details, [
            'source' => 'tmdb',
            'confidence' => 1.0,
            'method' => 'manual',
            'tmdb_season' => $tmdbSeason,
            'tmdb_episode' => $tmdbEpisode,
        ], MetadataReviewStatus::ManuallyMatched);
        $this->mediaEvents->record($episode->user, MediaEventType::MetadataEnriched, $episode, [
            'title' => $episode->title,
            'media_type' => 'episode',
            'show_id' => $episode->show_id,
            'tmdb_id' => $episode->tmdb_id,
            'match' => [
                'source' => 'tmdb',
                'confidence' => 1.0,
                'method' => 'manual',
            ],
        ], MediaEventSource::Metadata);

        return $this->summary(planned: 1, matched: 1, enriched: 1);
    }

    /**
     * @return array<string, int>
     */
    public function statusForUser(User $user): array
    {
        $missingEpisodes = Episode::forUser($user)->where(function (Builder $query): void {
            $query
                ->whereNull('tmdb_id')
                ->orWhereNull('metadata_refreshed_at');
        });
        $blockedByParent = (clone $missingEpisodes)
            ->whereDoesntHave('show', fn (Builder $query) => $query->whereNotNull('tmdb_id'));
        $parentMatchedMissing = (clone $missingEpisodes)
            ->whereHas('show', fn (Builder $query) => $query->whereNotNull('tmdb_id'));
        $invalidNumbering = (clone $parentMatchedMissing)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('season_number')
                    ->orWhereNull('episode_number')
                    ->orWhere('season_number', '<=', 0)
                    ->orWhere('episode_number', '<=', 0);
            });
        $eligibleEpisodes = (clone $missingEpisodes)
            ->whereHas('show', fn (Builder $query) => $query->whereNotNull('tmdb_id'))
            ->where('season_number', '>', 0)
            ->where('episode_number', '>', 0);
        $eligibleEpisodes = $this->reviewableEpisodeFilter($eligibleEpisodes);

        return [
            'movies_total' => Movie::forUser($user)->count(),
            'movies_enriched' => Movie::forUser($user)->whereNotNull('tmdb_id')->count(),
            'shows_total' => Show::forUser($user)->count(),
            'shows_enriched' => Show::forUser($user)->whereNotNull('tmdb_id')->count(),
            'episodes_total' => Episode::forUser($user)->count(),
            'episodes_enriched' => Episode::forUser($user)->whereNotNull('tmdb_id')->count(),
            'episodes_missing_metadata' => (clone $missingEpisodes)->count(),
            'episodes_blocked_no_parent_tmdb' => $blockedByParent->count(),
            'episodes_not_enrichable_invalid_numbering' => $invalidNumbering->count(),
            'episodes_eligible_for_enrichment' => $eligibleEpisodes->count(),
        ];
    }

    public function imageUrl(?string $path, string $size = 'w500'): string
    {
        if (! $path) {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('tmdb.image_base_url'), '/').'/'.$size.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function applyDiscoveredMovie(Movie $movie, array $details): void
    {
        $this->applyMovieDetails($movie, $details, [
            'source' => 'tmdb',
            'confidence' => 1.0,
            'method' => 'discovery',
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function applyDiscoveredShow(Show $show, array $details): void
    {
        $this->applyShowDetails($show, $details, [
            'source' => 'tmdb',
            'confidence' => 1.0,
            'method' => 'discovery',
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function applyCatalogEpisode(Episode $episode, array $details): void
    {
        if (blank($episode->title) && filled($details['name'] ?? null)) {
            $episode->forceFill(['title' => (string) $details['name']]);
        }

        $reviewStatus = MetadataReviewStatus::tryFrom((string) $episode->metadata_review_status)
            ?? MetadataReviewStatus::Pending;
        $this->applyEpisodeDetails($episode, $details, [
            'source' => 'tmdb',
            'confidence' => 1.0,
            'method' => 'season_catalog',
        ], $reviewStatus);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function enrichEpisodesForShow(Show $show, array $options = []): array
    {
        if (! $show->tmdb_id) {
            return $this->summary(skipped: 1);
        }

        $summary = $this->emptySummary();

        Episode::forUser($show->user)
            ->where('show_id', $show->id)
            ->whereNotNull('season_number')
            ->whereNotNull('episode_number')
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get()
            ->each(function (Episode $episode) use (&$summary, $options): void {
                $summary = $this->add($summary, $this->enrichEpisode($episode));
                $this->sleepBetweenRecords($options);
            });

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $match
     */
    private function applyMovieDetails(Movie $movie, array $details, ?array $match): void
    {
        $genres = $this->genres($details['genres'] ?? []);
        $movie->forceFill([
            'tmdb_id' => $this->intOrNull($details['id'] ?? null),
            'imdb_id' => $this->stringOrNull($details['imdb_id'] ?? null) ?: $movie->imdb_id,
            'original_title' => $this->stringOrNull($details['original_title'] ?? null) ?: $movie->original_title,
            'overview' => $this->stringOrNull($details['overview'] ?? null) ?: $movie->overview,
            'poster_path' => $this->stringOrNull($details['poster_path'] ?? null) ?: $movie->poster_path,
            'backdrop_path' => $this->stringOrNull($details['backdrop_path'] ?? null) ?: $movie->backdrop_path,
            'release_date' => $this->stringOrNull($details['release_date'] ?? null) ?: $movie->release_date,
            'genres' => $genres !== [] ? $genres : ($movie->genres ?? []),
            'runtime' => $this->runtimeValue($movie->runtime, $details['runtime'] ?? null),
            'status' => $this->stringOrNull($details['status'] ?? null),
            'vote_average' => $this->floatOrNull($details['vote_average'] ?? null),
            'metadata' => $this->metadata($movie->metadata ?? [], $match, 'movie', $details),
            'metadata_refreshed_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $match
     */
    private function applyShowDetails(Show $show, array $details, ?array $match): void
    {
        $externalIds = is_array($details['external_ids'] ?? null) ? $details['external_ids'] : [];
        $genres = $this->genres($details['genres'] ?? []);

        $show->forceFill([
            'tmdb_id' => $this->intOrNull($details['id'] ?? null),
            'imdb_id' => $this->stringOrNull($externalIds['imdb_id'] ?? null) ?: $show->imdb_id,
            'tvdb_id' => $this->stringOrNull($externalIds['tvdb_id'] ?? null) ?: $show->tvdb_id,
            'original_title' => $this->stringOrNull($details['original_name'] ?? null) ?: $show->original_title,
            'overview' => $this->stringOrNull($details['overview'] ?? null) ?: $show->overview,
            'poster_path' => $this->stringOrNull($details['poster_path'] ?? null) ?: $show->poster_path,
            'backdrop_path' => $this->stringOrNull($details['backdrop_path'] ?? null) ?: $show->backdrop_path,
            'first_air_date' => $this->stringOrNull($details['first_air_date'] ?? null) ?: $show->first_air_date,
            'genres' => $genres !== [] ? $genres : ($show->genres ?? []),
            'runtime' => $this->runtimeValue($show->runtime, $this->firstRuntime($details['episode_run_time'] ?? [])),
            'status' => $this->stringOrNull($details['status'] ?? null),
            'vote_average' => $this->floatOrNull($details['vote_average'] ?? null),
            'metadata' => $this->showMetadata($show->metadata ?? [], $match, $details),
            'metadata_refreshed_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $match
     */
    private function applyEpisodeDetails(Episode $episode, array $details, ?array $match, MetadataReviewStatus $reviewStatus = MetadataReviewStatus::Pending): void
    {
        $externalIds = is_array($details['external_ids'] ?? null) ? $details['external_ids'] : [];

        $episode->forceFill([
            'tmdb_id' => $this->intOrNull($details['id'] ?? null) ?: $episode->tmdb_id,
            'imdb_id' => $this->stringOrNull($externalIds['imdb_id'] ?? null) ?: $episode->imdb_id,
            'tvdb_id' => $this->stringOrNull($externalIds['tvdb_id'] ?? null) ?: $episode->tvdb_id,
            'original_title' => $this->stringOrNull($details['name'] ?? null) ?: $episode->original_title,
            'overview' => $this->stringOrNull($details['overview'] ?? null) ?: $episode->overview,
            'poster_path' => $this->stringOrNull($details['still_path'] ?? null) ?: $episode->poster_path,
            'backdrop_path' => $this->stringOrNull($details['still_path'] ?? null) ?: $episode->backdrop_path,
            'runtime' => $this->runtimeValue($episode->runtime, $details['runtime'] ?? null),
            'air_date' => $this->stringOrNull($details['air_date'] ?? null) ?: $episode->air_date,
            'vote_average' => $this->floatOrNull($details['vote_average'] ?? null) ?? $episode->vote_average,
            'metadata' => $this->metadata($episode->metadata ?? [], $match, 'episode', $details),
            'metadata_refreshed_at' => now(),
            'last_metadata_failure_reason' => null,
            'metadata_failed_at' => null,
            'metadata_failure_count' => 0,
            'metadata_review_status' => $reviewStatus->value,
        ])->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bestSearchResult(string $title, mixed $results, string $titleKey): ?array
    {
        if (! is_array($results)) {
            return null;
        }

        $collection = collect($results)->filter(fn (mixed $row): bool => is_array($row) && filled($row['id'] ?? null));

        if ($collection->isEmpty()) {
            return null;
        }

        return $collection
            ->sortByDesc(fn (array $row): float => $this->confidence($title, (string) ($row[$titleKey] ?? '')))
            ->first();
    }

    private function confidence(string $expected, string $candidate): float
    {
        if ($this->normalize($expected) === $this->normalize($candidate)) {
            return 0.95;
        }

        similar_text($this->normalize($expected), $this->normalize($candidate), $percentage);

        return round(max(0.5, min(0.9, $percentage / 100)), 2);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>|null  $match
     * @return array<string, mixed>
     */
    private function metadata(array $existing, ?array $match, string $type, array $details = []): array
    {
        return [
            ...$existing,
            'match' => $match,
            ...($details !== [] ? ['public' => $this->publicMetadata($details, $type)] : []),
            'tmdb' => [
                'type' => $type,
                'refreshed_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>|null  $match
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function showMetadata(array $existing, ?array $match, array $details): array
    {
        $metadata = $this->metadata($existing, $match, 'show', $details);
        $nextEpisode = is_array($details['next_episode_to_air'] ?? null)
            ? $details['next_episode_to_air']
            : null;

        $metadata['release'] = [
            'next_episode' => $nextEpisode ? [
                'tmdb_id' => $this->intOrNull($nextEpisode['id'] ?? null),
                'season_number' => $this->intOrNull($nextEpisode['season_number'] ?? null),
                'episode_number' => $this->intOrNull($nextEpisode['episode_number'] ?? null),
                'name' => $this->stringOrNull($nextEpisode['name'] ?? null),
                'air_date' => $this->stringOrNull($nextEpisode['air_date'] ?? null),
                'overview' => $this->stringOrNull($nextEpisode['overview'] ?? null),
                'runtime' => $this->intOrNull($nextEpisode['runtime'] ?? null),
                'still_path' => $this->stringOrNull($nextEpisode['still_path'] ?? null),
            ] : null,
        ];

        return $metadata;
    }

    /**
     * Keep only display-safe TMDB fields. Raw responses never enter API payloads.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function publicMetadata(array $details, string $type): array
    {
        $credits = is_array($details[$type === 'show' ? 'aggregate_credits' : 'credits'] ?? null)
            ? $details[$type === 'show' ? 'aggregate_credits' : 'credits']
            : [];
        $cast = collect($credits['cast'] ?? [])->filter(fn (mixed $person): bool => is_array($person) && filled($person['name'] ?? null))
            ->sortBy(fn (array $person): int => (int) ($person['order'] ?? 9999))
            ->take(12)
            ->map(function (array $person) use ($type): array {
                $role = $type === 'show'
                    ? data_get($person, 'roles.0.character')
                    : ($person['character'] ?? null);

                return [
                    'tmdb_id' => $this->intOrNull($person['id'] ?? null),
                    'name' => (string) $person['name'],
                    'role' => $this->stringOrNull($role),
                    'profile_path' => $this->stringOrNull($person['profile_path'] ?? null),
                ];
            })->values()->all();
        $directors = collect($credits['crew'] ?? [])->filter(function (mixed $person): bool {
            if (! is_array($person) || blank($person['name'] ?? null)) {
                return false;
            }

            return ($person['job'] ?? null) === 'Director'
                || collect($person['jobs'] ?? [])->contains(fn (mixed $job): bool => is_array($job) && ($job['job'] ?? null) === 'Director');
        })->map(fn (array $person): array => [
            'tmdb_id' => $this->intOrNull($person['id'] ?? null),
            'name' => (string) $person['name'],
            'role' => 'Director',
            'profile_path' => $this->stringOrNull($person['profile_path'] ?? null),
        ]);
        $creators = collect($details['created_by'] ?? [])->filter(fn (mixed $person): bool => is_array($person) && filled($person['name'] ?? null))
            ->map(fn (array $person): array => [
                'tmdb_id' => $this->intOrNull($person['id'] ?? null),
                'name' => (string) $person['name'],
                'role' => 'Creator',
                'profile_path' => $this->stringOrNull($person['profile_path'] ?? null),
            ]);

        return [
            'tagline' => $this->stringOrNull($details['tagline'] ?? null),
            'original_title' => $this->stringOrNull($details[$type === 'show' ? 'original_name' : 'original_title'] ?? null),
            'cast' => $cast,
            'directors' => $directors->concat($creators)->unique('tmdb_id')->take(8)->values()->all(),
            'companies' => collect($details['production_companies'] ?? [])->pluck('name')->filter()->take(8)->values()->all(),
            'countries' => collect($details['production_countries'] ?? [])->pluck('name')->filter()->take(8)->values()->all(),
            'languages' => collect($details['spoken_languages'] ?? [])->map(fn (mixed $language): mixed => is_array($language) ? ($language['english_name'] ?? $language['name'] ?? null) : null)->filter()->take(8)->values()->all(),
        ];
    }

    /**
     * @return list<array{id:int|null,name:string}>
     */
    private function genres(mixed $genres): array
    {
        if (! is_array($genres)) {
            return [];
        }

        return collect($genres)
            ->filter(fn (mixed $genre): bool => is_array($genre) && filled($genre['name'] ?? null))
            ->map(fn (array $genre): array => [
                'id' => $this->intOrNull($genre['id'] ?? null),
                'name' => (string) $genre['name'],
            ])
            ->values()
            ->all();
    }

    private function firstRuntime(mixed $values): ?int
    {
        if (! is_array($values)) {
            return null;
        }

        return collect($values)
            ->map(fn (mixed $value): ?int => $this->intOrNull($value))
            ->filter(fn (?int $value): bool => $value !== null && $value > 0)
            ->first();
    }

    private function yearFromDate(mixed $date): ?int
    {
        if (! $date) {
            return null;
        }

        if ($date instanceof CarbonInterface) {
            return (int) $date->format('Y');
        }

        try {
            return (int) CarbonImmutable::parse((string) $date)->format('Y');
        } catch (Throwable) {
            return null;
        }
    }

    private function runtimeValue(mixed $current, mixed $candidate): int
    {
        $currentRuntime = $this->intOrNull($current) ?? 0;
        $candidateRuntime = $this->intOrNull($candidate) ?? 0;

        if ($candidateRuntime > 0) {
            return $candidateRuntime;
        }

        return max(0, $currentRuntime);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{type:string,limit:int|null,only_missing:bool,only_parent_enriched:bool,dry_run:bool,sleep_ms:int,min_confidence:float}
     */
    private function normalizeOptions(array $options): array
    {
        $type = in_array(($options['type'] ?? 'all'), ['movies', 'shows', 'episodes', 'all'], true)
            ? (string) ($options['type'] ?? 'all')
            : 'all';
        $limit = is_numeric($options['limit'] ?? null) && (int) $options['limit'] > 0
            ? (int) $options['limit']
            : null;
        $sleepMs = is_numeric($options['sleep_ms'] ?? null)
            ? max(0, (int) $options['sleep_ms'])
            : 0;
        $minConfidence = is_numeric($options['min_confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $options['min_confidence']))
            : 0.0;

        return [
            'type' => $type,
            'limit' => $limit,
            'only_missing' => (bool) ($options['only_missing'] ?? false),
            'only_parent_enriched' => (bool) ($options['only_parent_enriched'] ?? false),
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'sleep_ms' => $sleepMs,
            'min_confidence' => $minConfidence,
        ];
    }

    /**
     * @param  Builder<Movie|Show|Episode>  $query
     * @param  array{type:string,limit:int|null,only_missing:bool,only_parent_enriched:bool,dry_run:bool,sleep_ms:int,min_confidence:float}  $options
     * @return Builder<Movie|Show|Episode>
     */
    private function missingFilter(Builder $query, array $options): Builder
    {
        if (! $options['only_missing']) {
            return $query;
        }

        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('tmdb_id')
                ->orWhereNull('metadata_refreshed_at');
        });
    }

    /**
     * @param  Builder<Movie|Show|Episode>  $query
     * @param  array{type:string,limit:int|null,only_missing:bool,only_parent_enriched:bool,dry_run:bool,sleep_ms:int,min_confidence:float}  $options
     * @return Builder<Movie|Show|Episode>
     */
    private function episodeEligibilityFilter(Builder $query, array $options): Builder
    {
        if (! $options['only_parent_enriched']) {
            return $query;
        }

        return $query
            ->whereHas('show', fn (Builder $query) => $query->whereNotNull('tmdb_id'))
            ->where('season_number', '>', 0)
            ->where('episode_number', '>', 0)
            ->where(fn (Builder $query) => $this->reviewableEpisodeFilter($query));
    }

    /**
     * @param  Builder<Episode>  $query
     * @return Builder<Episode>
     */
    private function reviewableEpisodeFilter(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('metadata_review_status')
                    ->orWhere('metadata_review_status', '!=', MetadataReviewStatus::Ignored->value);
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('last_metadata_failure_reason', '!=', 'tmdb_404')
                    ->orWhereNull('last_metadata_failure_reason')
                    ->orWhere('metadata_failure_count', '<', self::EPISODE_FAILURE_THRESHOLD);
            });
    }

    /**
     * @param  array{endpoint:string,status:int|null,reason:string}|null  $failure
     */
    private function metadataFailureReason(?array $failure): string
    {
        return match ($failure['status'] ?? null) {
            404 => 'tmdb_404',
            429 => 'tmdb_rate_limited',
            null => 'tmdb_unavailable',
            default => 'tmdb_http_'.(int) $failure['status'],
        };
    }

    private function trackEpisodeFailure(Episode $episode, string $reason): void
    {
        $episode->forceFill([
            'last_metadata_failure_reason' => $reason,
            'metadata_failed_at' => now(),
            'metadata_failure_count' => max(0, (int) $episode->metadata_failure_count) + 1,
            'metadata_review_status' => $episode->metadata_review_status ?: MetadataReviewStatus::Pending->value,
        ])->save();
    }

    /**
     * @param  Builder<Movie|Show|Episode>  $query
     * @return Builder<Movie|Show|Episode>
     */
    private function limitedQuery(Builder $query, ?int $remaining): Builder
    {
        if ($remaining !== null) {
            $query->limit($remaining);
        }

        return $query;
    }

    private function hasRemaining(?int $remaining): bool
    {
        return $remaining === null || $remaining > 0;
    }

    private function consumeRemaining(?int &$remaining, int $count): void
    {
        if ($remaining !== null) {
            $remaining = max(0, $remaining - $count);
        }
    }

    /**
     * @param  array{type:string,limit:int|null,only_missing:bool,only_parent_enriched:bool,dry_run:bool,sleep_ms:int,min_confidence:float}  $options
     */
    private function sleepBetweenRecords(array $options): void
    {
        if ($options['sleep_ms'] > 0) {
            usleep($options['sleep_ms'] * 1000);
        }
    }

    /**
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function emptySummary(): array
    {
        return $this->summary();
    }

    /**
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function summary(int $planned = 0, int $searched = 0, int $matched = 0, int $enriched = 0, int $skipped = 0, int $failed = 0): array
    {
        return compact('planned', 'searched', 'matched', 'enriched', 'skipped', 'failed');
    }

    /**
     * @param  array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}  $left
     * @param  array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}  $right
     * @return array{planned:int,searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function add(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] += $value;
        }

        return $left;
    }
}
