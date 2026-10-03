<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TMDBClientService
{
    /**
     * @var array{endpoint:string,status:int|null,reason:string}|null
     */
    private ?array $lastFailure = null;

    public function enabled(): bool
    {
        return (bool) config('tmdb.enabled') && filled(config('tmdb.api_key'));
    }

    /**
     * @return array{endpoint:string,status:int|null,reason:string}|null
     */
    public function lastFailure(): ?array
    {
        return $this->lastFailure;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchMovie(string $title, ?int $year = null, int $page = 1): ?array
    {
        return $this->get('/search/movie', array_filter([
            'query' => $title,
            'year' => $year,
            'include_adult' => false,
            'language' => 'en-US',
            'page' => max(1, $page),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchShow(string $title, ?int $year = null, int $page = 1): ?array
    {
        return $this->get('/search/tv', array_filter([
            'query' => $title,
            'first_air_date_year' => $year,
            'include_adult' => false,
            'language' => 'en-US',
            'page' => max(1, $page),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchMulti(string $title, ?int $year = null, int $page = 1): ?array
    {
        return $this->get('/search/multi', array_filter([
            'query' => $title,
            'year' => $year,
            'include_adult' => false,
            'language' => 'en-US',
            'page' => max(1, $page),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function browse(string $category, string $type, int $page = 1): ?array
    {
        $paths = [
            'movie' => [
                'trending' => '/trending/movie/week',
                'popular' => '/movie/popular',
                'now_playing' => '/movie/now_playing',
                'upcoming' => '/movie/upcoming',
                'top_rated' => '/movie/top_rated',
            ],
            'show' => [
                'trending' => '/trending/tv/week',
                'popular' => '/tv/popular',
                'now_playing' => '/tv/airing_today',
                'upcoming' => '/tv/on_the_air',
                'top_rated' => '/tv/top_rated',
            ],
        ];
        $path = $paths[$type][$category] ?? null;

        if (! $path) {
            return null;
        }

        return $this->get($path, [
            'language' => 'en-US',
            'page' => max(1, $page),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function discoverFiltered(string $type, array $filters, int $page = 1): ?array
    {
        return $this->get('/discover/'.($type === 'show' ? 'tv' : 'movie'), array_filter([
            'language' => 'en-US', 'page' => $page, 'include_adult' => false,
            'sort_by' => ($filters['category'] ?? '') === 'top_rated' ? 'vote_average.desc' : 'popularity.desc',
            ($type === 'show' ? 'air_date.gte' : 'primary_release_date.gte') => ($filters['category'] ?? '') === 'upcoming' ? now()->toDateString() : null,
            'with_runtime.gte' => isset($filters['max_runtime']) ? 1 : null,
            'with_genres' => $filters['genre_id'] ?? null,
            'with_original_language' => $filters['language'] ?? null,
            'with_runtime.lte' => $filters['max_runtime'] ?? null,
            'vote_average.gte' => $filters['min_rating'] ?? null,
            'vote_count.gte' => 20,
            $type === 'show' ? 'first_air_date_year' : 'primary_release_year' => $filters['year'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function getMovie(int $tmdbId): ?array
    {
        return $this->get('/movie/'.$tmdbId, [
            'append_to_response' => 'external_ids,credits',
            'language' => 'en-US',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getShow(int $tmdbId): ?array
    {
        return $this->get('/tv/'.$tmdbId, [
            'append_to_response' => 'external_ids,aggregate_credits',
            'language' => 'en-US',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSeason(int $showTmdbId, int $seasonNumber): ?array
    {
        return $this->get('/tv/'.$showTmdbId.'/season/'.$seasonNumber, [
            'language' => 'en-US',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEpisode(int $showTmdbId, int $seasonNumber, int $episodeNumber): ?array
    {
        return $this->get('/tv/'.$showTmdbId.'/season/'.$seasonNumber.'/episode/'.$episodeNumber, [
            'append_to_response' => 'external_ids',
            'language' => 'en-US',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $params = []): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $this->lastFailure = null;
        $cacheKey = 'tmdb:'.sha1($path.'|'.json_encode($params, JSON_THROW_ON_ERROR));
        $ttl = max(60, (int) config('tmdb.cache_ttl', 86400));

        return Cache::store((string) config('tmdb.cache_store', 'file'))
            ->remember($cacheKey, $ttl, function () use ($params, $path): ?array {
                try {
                    $response = Http::timeout(max(1, (int) config('tmdb.timeout', 20)))
                        ->acceptJson()
                        ->get(rtrim((string) config('tmdb.base_url'), '/').$path, [
                            ...$params,
                            'api_key' => config('tmdb.api_key'),
                        ]);
                } catch (Throwable) {
                    $this->lastFailure = [
                        'endpoint' => $path,
                        'status' => null,
                        'reason' => 'request_failed',
                    ];
                    Log::warning('TMDB request failed.', ['endpoint' => $path]);

                    return null;
                }

                if (! $response->ok()) {
                    $this->lastFailure = [
                        'endpoint' => $path,
                        'status' => $response->status(),
                        'reason' => 'http_status',
                    ];
                    Log::warning('TMDB request returned non-success status.', [
                        'endpoint' => $path,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $payload = $response->json();

                return is_array($payload) ? $payload : null;
            });
    }
}
