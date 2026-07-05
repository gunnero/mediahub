<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

class MediaMetadataService
{
    public function __construct(
        private readonly TMDBClientService $tmdb,
        private readonly MediaEventService $mediaEvents,
    ) {}

    /**
     * @return array{searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function enrichMovie(Movie $movie): array
    {
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

            $match = [
                'source' => 'tmdb',
                'confidence' => $this->confidence($movie->title, (string) ($result['title'] ?? '')),
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
     * @return array{searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function enrichShow(Show $show, bool $enrichEpisodes = false): array
    {
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

            $match = [
                'source' => 'tmdb',
                'confidence' => $this->confidence($show->title, (string) ($result['name'] ?? '')),
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
            $summary = $this->add($summary, $this->enrichEpisodesForShow($show->refresh()));
        }

        return $summary;
    }

    /**
     * @return array{searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    public function enrichUser(User $user): array
    {
        $summary = $this->emptySummary();

        Movie::forUser($user)
            ->orderBy('id')
            ->get()
            ->each(function (Movie $movie) use (&$summary): void {
                $summary = $this->add($summary, $this->enrichMovie($movie));
            });

        Show::forUser($user)
            ->orderBy('id')
            ->get()
            ->each(function (Show $show) use (&$summary): void {
                $summary = $this->add($summary, $this->enrichShow($show, enrichEpisodes: true));
            });

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function statusForUser(User $user): array
    {
        return [
            'movies_total' => Movie::forUser($user)->count(),
            'movies_enriched' => Movie::forUser($user)->whereNotNull('tmdb_id')->count(),
            'shows_total' => Show::forUser($user)->count(),
            'shows_enriched' => Show::forUser($user)->whereNotNull('tmdb_id')->count(),
            'episodes_total' => Episode::forUser($user)->count(),
            'episodes_enriched' => Episode::forUser($user)->whereNotNull('tmdb_id')->count(),
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
     * @return array{searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function enrichEpisodesForShow(Show $show): array
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
            ->each(function (Episode $episode) use (&$summary, $show): void {
                $details = $this->tmdb->getEpisode((int) $show->tmdb_id, (int) $episode->season_number, (int) $episode->episode_number);

                if (! $details) {
                    $summary = $this->add($summary, $this->summary(failed: 1));

                    return;
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
                $summary = $this->add($summary, $this->summary(matched: 1, enriched: 1));
            });

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $match
     */
    private function applyMovieDetails(Movie $movie, array $details, ?array $match): void
    {
        $movie->forceFill([
            'tmdb_id' => $this->intOrNull($details['id'] ?? null),
            'imdb_id' => $this->stringOrNull($details['imdb_id'] ?? null),
            'original_title' => $this->stringOrNull($details['original_title'] ?? null),
            'overview' => $this->stringOrNull($details['overview'] ?? null),
            'poster_path' => $this->stringOrNull($details['poster_path'] ?? null),
            'backdrop_path' => $this->stringOrNull($details['backdrop_path'] ?? null),
            'release_date' => $this->stringOrNull($details['release_date'] ?? null),
            'genres' => $this->genres($details['genres'] ?? []),
            'runtime' => $this->runtimeValue($movie->runtime, $details['runtime'] ?? null),
            'status' => $this->stringOrNull($details['status'] ?? null),
            'vote_average' => $this->floatOrNull($details['vote_average'] ?? null),
            'metadata' => $this->metadata($movie->metadata ?? [], $match, 'movie'),
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

        $show->forceFill([
            'tmdb_id' => $this->intOrNull($details['id'] ?? null),
            'imdb_id' => $this->stringOrNull($externalIds['imdb_id'] ?? null),
            'tvdb_id' => $this->stringOrNull($externalIds['tvdb_id'] ?? null),
            'original_title' => $this->stringOrNull($details['original_name'] ?? null),
            'overview' => $this->stringOrNull($details['overview'] ?? null),
            'poster_path' => $this->stringOrNull($details['poster_path'] ?? null),
            'backdrop_path' => $this->stringOrNull($details['backdrop_path'] ?? null),
            'first_air_date' => $this->stringOrNull($details['first_air_date'] ?? null),
            'genres' => $this->genres($details['genres'] ?? []),
            'runtime' => $this->runtimeValue($show->runtime, $this->firstRuntime($details['episode_run_time'] ?? [])),
            'status' => $this->stringOrNull($details['status'] ?? null),
            'vote_average' => $this->floatOrNull($details['vote_average'] ?? null),
            'metadata' => $this->metadata($show->metadata ?? [], $match, 'show'),
            'metadata_refreshed_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $match
     */
    private function applyEpisodeDetails(Episode $episode, array $details, ?array $match): void
    {
        $externalIds = is_array($details['external_ids'] ?? null) ? $details['external_ids'] : [];

        $episode->forceFill([
            'tmdb_id' => $this->intOrNull($details['id'] ?? null),
            'imdb_id' => $this->stringOrNull($externalIds['imdb_id'] ?? null),
            'tvdb_id' => $this->stringOrNull($externalIds['tvdb_id'] ?? null),
            'original_title' => $this->stringOrNull($details['name'] ?? null),
            'overview' => $this->stringOrNull($details['overview'] ?? null),
            'poster_path' => $this->stringOrNull($details['still_path'] ?? null),
            'backdrop_path' => $this->stringOrNull($details['still_path'] ?? null),
            'runtime' => $this->runtimeValue($episode->runtime, $details['runtime'] ?? null),
            'air_date' => $episode->air_date ?: $this->stringOrNull($details['air_date'] ?? null),
            'vote_average' => $this->floatOrNull($details['vote_average'] ?? null),
            'metadata' => $this->metadata($episode->metadata ?? [], $match, 'episode'),
            'metadata_refreshed_at' => now(),
        ])->save();
    }

    /**
     * @param  mixed  $results
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
    private function metadata(array $existing, ?array $match, string $type): array
    {
        return [
            ...$existing,
            'match' => $match,
            'tmdb' => [
                'type' => $type,
                'refreshed_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  mixed  $genres
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

        if ($currentRuntime > 0) {
            return $currentRuntime;
        }

        return max(0, $this->intOrNull($candidate) ?? 0);
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
     * @return array{searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function emptySummary(): array
    {
        return $this->summary();
    }

    /**
     * @return array{searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function summary(int $searched = 0, int $matched = 0, int $enriched = 0, int $skipped = 0, int $failed = 0): array
    {
        return compact('searched', 'matched', 'enriched', 'skipped', 'failed');
    }

    /**
     * @param  array{searched:int,matched:int,enriched:int,skipped:int,failed:int}  $left
     * @param  array{searched:int,matched:int,enriched:int,skipped:int,failed:int}  $right
     * @return array{searched:int,matched:int,enriched:int,skipped:int,failed:int}
     */
    private function add(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] += $value;
        }

        return $left;
    }
}
