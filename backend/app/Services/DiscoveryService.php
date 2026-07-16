<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscoveryService
{
    private const MOVIE_GENRES = [
        12 => 'Adventure', 14 => 'Fantasy', 16 => 'Animation', 18 => 'Drama', 27 => 'Horror',
        28 => 'Action', 35 => 'Comedy', 36 => 'History', 37 => 'Western', 53 => 'Thriller',
        80 => 'Crime', 99 => 'Documentary', 878 => 'Science Fiction', 9648 => 'Mystery',
        10402 => 'Music', 10749 => 'Romance', 10751 => 'Family', 10752 => 'War', 10770 => 'TV Movie',
    ];

    private const SHOW_GENRES = [
        16 => 'Animation', 18 => 'Drama', 35 => 'Comedy', 37 => 'Western', 80 => 'Crime',
        99 => 'Documentary', 9648 => 'Mystery', 10751 => 'Family', 10759 => 'Action & Adventure',
        10762 => 'Kids', 10763 => 'News', 10764 => 'Reality', 10765 => 'Sci-Fi & Fantasy',
        10766 => 'Soap', 10767 => 'Talk', 10768 => 'War & Politics',
    ];

    public function __construct(
        private readonly TMDBClientService $tmdb,
        private readonly MediaMetadataService $metadata,
        private readonly MediaEventService $events,
        private readonly PlaybackLibraryService $library,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(User $user, string $query, string $type = 'all', int $page = 1, ?int $year = null): array
    {
        if (! $this->tmdb->enabled()) {
            return $this->emptySearch('disabled', $page);
        }

        if ($type === 'all') {
            return $this->searchAll($user, $query, $page, $year);
        }

        $movies = in_array($type, ['movie', 'all'], true)
            ? $this->tmdb->searchMovie($query, $year, $page)
            : ['results' => [], 'page' => $page, 'total_pages' => 0, 'total_results' => 0];
        $shows = in_array($type, ['show', 'all'], true)
            ? $this->tmdb->searchShow($query, $year, $page)
            : ['results' => [], 'page' => $page, 'total_pages' => 0, 'total_results' => 0];

        if ($movies === null || $shows === null) {
            return $this->emptySearch('unavailable', $page);
        }

        $movieRows = collect($movies['results'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values();
        $showRows = collect($shows['results'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values();
        $movieIds = $movieRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $showIds = $showRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $existingMovies = $this->existingMovies($user, $movieIds);
        $existingShows = $this->existingShows($user, $showIds);

        $items = [
            ...$movieRows->map(fn (array $row): array => $this->searchResult($row, 'movie', $existingMovies->get((int) ($row['id'] ?? 0))))->all(),
            ...$showRows->map(fn (array $row): array => $this->searchResult($row, 'show', $existingShows->get((int) ($row['id'] ?? 0))))->all(),
        ];

        return [
            'status' => 'ready',
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'totalPages' => max((int) ($movies['total_pages'] ?? 0), (int) ($shows['total_pages'] ?? 0)),
                'totalResults' => (int) ($movies['total_results'] ?? 0) + (int) ($shows['total_results'] ?? 0),
            ],
        ];
    }

    /**
     * Return display-safe, read-only TMDB details without adding the title to the library.
     *
     * @return array<string, mixed>
     */
    public function detail(User $user, string $type, int $tmdbId): array
    {
        if (! $this->tmdb->enabled()) {
            return ['status' => 'disabled', 'item' => null];
        }

        $details = $type === 'show'
            ? $this->tmdb->getShow($tmdbId)
            : $this->tmdb->getMovie($tmdbId);

        if (! is_array($details)) {
            return ['status' => 'unavailable', 'item' => null];
        }

        $existing = $type === 'show'
            ? $this->existingShows($user, [$tmdbId])->get($tmdbId)
            : $this->existingMovies($user, [$tmdbId])->get($tmdbId);
        $public = $this->metadata->publicMetadata($details, $type);
        $date = (string) ($type === 'show' ? ($details['first_air_date'] ?? '') : ($details['release_date'] ?? ''));
        $runtime = $type === 'show'
            ? collect($details['episode_run_time'] ?? [])->map(fn (mixed $value): int => (int) $value)->first(fn (int $value): bool => $value > 0)
            : (int) ($details['runtime'] ?? 0);
        $person = fn (array $row): array => [
            'id' => $row['tmdb_id'] ?? null,
            'name' => (string) ($row['name'] ?? ''),
            'role' => $row['role'] ?? null,
            'image' => $this->metadata->imageUrl($row['profile_path'] ?? null, 'w185'),
        ];

        return [
            'status' => 'ready',
            'item' => [
                'media_type' => $type,
                'tmdb_id' => $tmdbId,
                'title' => (string) ($type === 'show' ? ($details['name'] ?? '') : ($details['title'] ?? '')),
                'original_title' => $public['original_title'],
                'year' => preg_match('/^\d{4}/', $date, $matches) ? (int) $matches[0] : null,
                'release_date' => $date ?: null,
                'poster' => $this->metadata->imageUrl($details['poster_path'] ?? null),
                'backdrop' => $this->metadata->imageUrl($details['backdrop_path'] ?? null, 'w1280'),
                'overview' => (string) ($details['overview'] ?? ''),
                'tagline' => $public['tagline'],
                'genres' => collect($details['genres'] ?? [])->pluck('name')->filter()->values()->all(),
                'runtime' => $runtime ?: null,
                'status' => $details['status'] ?? null,
                'vote_average' => isset($details['vote_average']) ? round((float) $details['vote_average'], 1) : null,
                'season_count' => $type === 'show' ? (int) ($details['number_of_seasons'] ?? 0) : null,
                'episode_count' => $type === 'show' ? (int) ($details['number_of_episodes'] ?? 0) : null,
                'people' => [
                    'cast' => collect($public['cast'])->map($person)->values()->all(),
                    'directors' => collect($public['directors'])->map($person)->values()->all(),
                ],
                'production' => [
                    'companies' => $public['companies'],
                    'countries' => $public['countries'],
                    'languages' => $public['languages'],
                ],
                'already_in_library' => $existing !== null,
                'existing_library_id' => $existing?->id,
                'watched' => (int) ($existing?->getAttribute('watched_count') ?? 0) > 0,
                'watched_count' => (int) ($existing?->getAttribute('watched_count') ?? 0),
                'watchlist' => $existing instanceof Movie ? (bool) $existing->is_to_watch : ($existing instanceof Show ? (bool) $existing->followed : false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchAll(User $user, string $query, int $page, ?int $year): array
    {
        $results = $this->tmdb->searchMulti($query, $year, $page);

        if ($results === null) {
            return $this->emptySearch('unavailable', $page);
        }

        $rows = collect($results['results'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && in_array($row['media_type'] ?? null, ['movie', 'tv'], true))
            ->values();
        $movieRows = $rows->filter(fn (array $row): bool => ($row['media_type'] ?? null) === 'movie')->values();
        $showRows = $rows->filter(fn (array $row): bool => ($row['media_type'] ?? null) === 'tv')->values();
        $movieIds = $movieRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $showIds = $showRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $existingMovies = $this->existingMovies($user, $movieIds);
        $existingShows = $this->existingShows($user, $showIds);

        $items = $rows
            ->map(fn (array $row): array => ($row['media_type'] ?? null) === 'movie'
                ? $this->searchResult($row, 'movie', $existingMovies->get((int) ($row['id'] ?? 0)))
                : $this->searchResult($row, 'show', $existingShows->get((int) ($row['id'] ?? 0))))
            ->all();

        return [
            'status' => 'ready',
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'totalPages' => (int) ($results['total_pages'] ?? 0),
                'totalResults' => (int) ($results['total_results'] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function browse(User $user, string $category = 'trending', string $type = 'all', int $page = 1): array
    {
        if (! $this->tmdb->enabled()) {
            return [...$this->emptySearch('disabled', $page), 'category' => $category, 'type' => $type];
        }

        $movies = in_array($type, ['movie', 'all'], true)
            ? $this->tmdb->browse($category, 'movie', $page)
            : ['results' => [], 'page' => $page, 'total_pages' => 0, 'total_results' => 0];
        $shows = in_array($type, ['show', 'all'], true)
            ? $this->tmdb->browse($category, 'show', $page)
            : ['results' => [], 'page' => $page, 'total_pages' => 0, 'total_results' => 0];

        if ($movies === null || $shows === null) {
            return [...$this->emptySearch('unavailable', $page), 'category' => $category, 'type' => $type];
        }

        $movieRows = collect($movies['results'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values();
        $showRows = collect($shows['results'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values();
        $existingMovies = $this->existingMovies($user, $movieRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all());
        $existingShows = $this->existingShows($user, $showRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all());
        $items = collect([
            ...$movieRows->map(fn (array $row): array => $this->searchResult($row, 'movie', $existingMovies->get((int) ($row['id'] ?? 0))))->all(),
            ...$showRows->map(fn (array $row): array => $this->searchResult($row, 'show', $existingShows->get((int) ($row['id'] ?? 0))))->all(),
        ])->sortByDesc(fn (array $item): float => (float) ($item['popularity'] ?? 0))->values();

        return [
            'status' => 'ready',
            'category' => $category,
            'type' => $type,
            'items' => $items->take(40)->all(),
            'pagination' => [
                'page' => $page,
                'totalPages' => max((int) ($movies['total_pages'] ?? 0), (int) ($shows['total_pages'] ?? 0)),
                'totalResults' => (int) ($movies['total_results'] ?? 0) + (int) ($shows['total_results'] ?? 0),
            ],
        ];
    }

    public function addMovie(User $user, int $tmdbId, string $action): Movie
    {
        $details = $this->tmdb->getMovie($tmdbId);

        if (! $details) {
            throw ValidationException::withMessages(['tmdb_id' => 'Movie metadata is currently unavailable.']);
        }

        return DB::transaction(function () use ($action, $details, $tmdbId, $user): Movie {
            $movie = $this->existingMovie($user, $tmdbId, $details);
            $isNew = $movie === null;
            $movie ??= Movie::create([
                'user_id' => $user->id,
                'external_source' => 'tmdb',
                'external_id' => (string) $tmdbId,
                'tmdb_id' => $tmdbId,
                'title' => (string) ($details['title'] ?? $details['original_title'] ?? 'Untitled movie'),
                'runtime' => 0,
                'is_to_watch' => false,
            ]);

            $this->metadata->applyDiscoveredMovie($movie, $details);
            if ($action === 'watchlist') {
                $movie->forceFill(['is_to_watch' => true])->save();
            }

            if ($isNew) {
                $this->events->record($user, MediaEventType::MovieAdded, $movie, [
                    'title' => $movie->title,
                    'media_type' => 'movie',
                    'tmdb_id' => $movie->tmdb_id,
                ], MediaEventSource::Discovery);
            }

            if ($action === 'watchlist') {
                $this->events->record($user, MediaEventType::WatchlistAdded, $movie, [
                    'title' => $movie->title,
                    'media_type' => 'movie',
                ], MediaEventSource::Discovery);
            }

            if ($action === 'watched') {
                $this->library->manuallyTrackMovie($user, $movie, []);
            }

            return $movie->refresh();
        });
    }

    public function addShow(User $user, int $tmdbId, string $action): Show
    {
        if ($action === 'watched') {
            throw ValidationException::withMessages(['action' => 'A whole show cannot be marked watched from discovery.']);
        }

        $details = $this->tmdb->getShow($tmdbId);

        if (! $details) {
            throw ValidationException::withMessages(['tmdb_id' => 'Show metadata is currently unavailable.']);
        }

        return DB::transaction(function () use ($action, $details, $tmdbId, $user): Show {
            $show = $this->existingShow($user, $tmdbId, $details);
            $isNew = $show === null;
            $show ??= Show::create([
                'user_id' => $user->id,
                'external_source' => 'tmdb',
                'external_id' => (string) $tmdbId,
                'tmdb_id' => $tmdbId,
                'title' => (string) ($details['name'] ?? $details['original_name'] ?? 'Untitled show'),
                'followed' => false,
                'runtime' => 0,
            ]);

            $this->metadata->applyDiscoveredShow($show, $details);
            if ($action === 'watchlist') {
                $show->forceFill(['followed' => true])->save();
            }

            if ($isNew) {
                $this->events->record($user, MediaEventType::ShowAdded, $show, [
                    'title' => $show->title,
                    'media_type' => 'show',
                    'tmdb_id' => $show->tmdb_id,
                ], MediaEventSource::Discovery);
            }

            if ($action === 'watchlist') {
                $this->events->record($user, MediaEventType::WatchlistAdded, $show, [
                    'title' => $show->title,
                    'media_type' => 'show',
                ], MediaEventSource::Discovery);
            }

            return $show->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function searchResult(array $row, string $type, Movie|Show|null $existing): array
    {
        $title = (string) ($type === 'movie' ? ($row['title'] ?? '') : ($row['name'] ?? ''));
        $originalTitle = (string) ($type === 'movie' ? ($row['original_title'] ?? '') : ($row['original_name'] ?? ''));
        $date = (string) ($type === 'movie' ? ($row['release_date'] ?? '') : ($row['first_air_date'] ?? ''));
        $genreMap = $type === 'movie' ? self::MOVIE_GENRES : self::SHOW_GENRES;

        return [
            'media_type' => $type,
            'tmdb_id' => (int) ($row['id'] ?? 0),
            'title' => $title ?: $originalTitle ?: 'Untitled',
            'original_title' => $originalTitle ?: null,
            'year' => preg_match('/^\d{4}/', $date, $matches) ? (int) $matches[0] : null,
            'poster' => $this->metadata->imageUrl($row['poster_path'] ?? null),
            'backdrop' => $this->metadata->imageUrl($row['backdrop_path'] ?? null, 'w780'),
            'overview' => (string) ($row['overview'] ?? ''),
            'genres' => collect($row['genre_ids'] ?? [])->map(fn (mixed $id): ?string => $genreMap[(int) $id] ?? null)->filter()->values()->all(),
            'popularity' => (float) ($row['popularity'] ?? 0),
            'already_in_library' => $existing !== null,
            'existing_library_id' => $existing?->id,
            'watched' => (int) ($existing?->getAttribute('watched_count') ?? 0) > 0,
            'watched_count' => (int) ($existing?->getAttribute('watched_count') ?? 0),
            'watchlist' => $existing instanceof Movie ? (bool) $existing->is_to_watch : ($existing instanceof Show ? (bool) $existing->followed : false),
        ];
    }

    /** @param list<int> $tmdbIds */
    private function existingMovies(User $user, array $tmdbIds): Collection
    {
        return Movie::forUser($user)
            ->whereIn('tmdb_id', $tmdbIds)
            ->withSum(['watches as watched_count' => fn ($query) => $query->where('user_id', $user->id)->watched()], 'watch_count')
            ->get()
            ->keyBy('tmdb_id');
    }

    /** @param list<int> $tmdbIds */
    private function existingShows(User $user, array $tmdbIds): Collection
    {
        return Show::forUser($user)
            ->whereIn('tmdb_id', $tmdbIds)
            ->withCount(['episodeWatches as watched_count' => fn ($query) => $query->where('user_id', $user->id)->watched()])
            ->get()
            ->keyBy('tmdb_id');
    }

    private function existingMovie(User $user, int $tmdbId, array $details): ?Movie
    {
        return Movie::forUser($user)
            ->where(function ($query) use ($details, $tmdbId): void {
                $query->where('tmdb_id', $tmdbId)
                    ->orWhere(function ($candidate) use ($details): void {
                        $candidate->where('title', (string) ($details['title'] ?? ''))
                            ->when(filled($details['release_date'] ?? null), fn ($dated) => $dated->whereDate('release_date', $details['release_date']));
                    });
            })
            ->first();
    }

    private function existingShow(User $user, int $tmdbId, array $details): ?Show
    {
        return Show::forUser($user)
            ->where(function ($query) use ($details, $tmdbId): void {
                $query->where('tmdb_id', $tmdbId)
                    ->orWhere(function ($candidate) use ($details): void {
                        $candidate->where('title', (string) ($details['name'] ?? ''))
                            ->when(filled($details['first_air_date'] ?? null), fn ($dated) => $dated->whereDate('first_air_date', $details['first_air_date']));
                    });
            })
            ->first();
    }

    /** @return array<string, mixed> */
    private function emptySearch(string $status, int $page): array
    {
        return [
            'status' => $status,
            'items' => [],
            'pagination' => ['page' => $page, 'totalPages' => 0, 'totalResults' => 0],
        ];
    }
}
