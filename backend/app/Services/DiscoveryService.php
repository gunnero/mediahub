<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
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
        $existingMovies = Movie::forUser($user)->whereIn('tmdb_id', $movieIds)->get()->keyBy('tmdb_id');
        $existingShows = Show::forUser($user)->whereIn('tmdb_id', $showIds)->get()->keyBy('tmdb_id');

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
        $existingMovies = Movie::forUser($user)
            ->whereIn('tmdb_id', $movieRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id))
            ->get()
            ->keyBy('tmdb_id');
        $existingShows = Show::forUser($user)
            ->whereIn('tmdb_id', $showRows->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id))
            ->get()
            ->keyBy('tmdb_id');
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
        ];
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
