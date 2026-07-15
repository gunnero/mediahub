<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

class LibraryBrowserService
{
    public function __construct(
        private readonly MediaMetadataService $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function movies(User $user, array $filters): array
    {
        $query = Movie::forUser($user);

        $this->applySearch($query, $filters['search'] ?? null, 'movies.title');

        match ($filters['status'] ?? 'all') {
            'watched' => $query->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('movie_watches')
                ->whereColumn('movie_watches.movie_id', 'movies.id')
                ->where('movie_watches.user_id', $user->id)),
            'watchlist' => $query->where('is_to_watch', true),
            'rated' => $this->whereHasAnnotation($query, $user, 'movie', Rating::class),
            'notes' => $this->whereHasAnnotation($query, $user, 'movie', Note::class),
            default => null,
        };

        $this->sortMovies($query, $user, (string) ($filters['sort'] ?? 'latest_watched'));

        [$items, $pagination] = $this->paginate($query, $filters);

        return [
            'items' => $this->movieCards($user, $items),
            'pagination' => $pagination,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function shows(User $user, array $filters): array
    {
        $query = Show::forUser($user);

        $this->applySearch($query, $filters['search'] ?? null, 'shows.title');

        match ($filters['status'] ?? 'all') {
            'followed' => $query->where('followed', true),
            'completed' => $query->where('aired_episodes', '>', 0)->whereColumn('seen_episodes', '>=', 'aired_episodes'),
            'in_progress' => $query->where('seen_episodes', '>', 0)->where(function (Builder $inner): void {
                $inner->whereColumn('seen_episodes', '<', 'aired_episodes')->orWhere('aired_episodes', 0);
            }),
            'new_episodes' => $query->whereColumn('aired_episodes', '>', 'seen_episodes'),
            'rated' => $this->whereHasAnnotation($query, $user, 'show', Rating::class),
            'notes' => $this->whereHasAnnotation($query, $user, 'show', Note::class),
            default => null,
        };

        $this->sortShows($query, $user, (string) ($filters['sort'] ?? 'latest_watched'));

        [$items, $pagination] = $this->paginate($query, $filters);

        return [
            'items' => $this->showCards($user, $items),
            'pagination' => $pagination,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>}
     */
    public function continueWatching(User $user, array $filters = []): array
    {
        $limit = max(1, min(12, (int) ($filters['limit'] ?? 3)));
        $candidateLimit = max($limit, min(60, (int) ($filters['candidate_limit'] ?? 30)));
        $today = CarbonImmutable::now($user->timezone ?? config('app.timezone'))->toDateString();

        $latestWatches = EpisodeWatch::query()
            ->select('show_id')
            ->selectRaw('MAX(watched_at) AS latest_watch_at')
            ->where('user_id', $user->id)
            ->whereNotNull('show_id')
            ->groupBy('show_id');

        $shows = Show::forUser($user)
            ->joinSub($latestWatches, 'latest_watches', 'latest_watches.show_id', '=', 'shows.id')
            ->select('shows.*')
            ->addSelect('latest_watches.latest_watch_at')
            ->orderByDesc('latest_watch_at')
            ->limit($candidateLimit)
            ->get();

        if ($shows->isEmpty()) {
            return ['items' => []];
        }

        $eligibleEpisodes = Episode::forUser($user)
            ->select(['episodes.id', 'episodes.show_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY episodes.show_id ORDER BY episodes.season_number, episodes.episode_number, episodes.id) AS row_number')
            ->whereIn('show_id', $shows->pluck('id'))
            ->where('season_number', '>', 0)
            ->where('episode_number', '>', 0)
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('air_date')->orWhereDate('air_date', '<=', $today);
            })
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('episode_watches')
                ->whereColumn('episode_watches.episode_id', 'episodes.id')
                ->where('episode_watches.user_id', $user->id));

        $episodeIds = DB::query()
            ->fromSub($eligibleEpisodes, 'eligible_episodes')
            ->where('row_number', 1)
            ->pluck('id');

        $episodes = Episode::forUser($user)
            ->with('show')
            ->whereIn('id', $episodeIds)
            ->get()
            ->keyBy('show_id');

        $selectedShows = $shows
            ->filter(fn (Show $show): bool => $episodes->has($show->id))
            ->take($limit)
            ->values();
        $selectedEpisodes = new EloquentCollection($selectedShows
            ->map(fn (Show $show): Episode => $episodes->get($show->id))
            ->all());
        $episodeContext = $this->episodeContext($user, $selectedEpisodes);

        $items = $selectedShows
            ->map(function (Show $show) use ($episodes, $user, $episodeContext): array {
                /** @var Episode|null $episode */
                $episode = $episodes->get($show->id);

                return [
                    ...$this->episodeCard($user, $episode, $episodeContext),
                    'showTitle' => $show->title,
                    'code' => $this->episodeCode($episode),
                    'latestWatchedAt' => $show->getAttribute('latest_watch_at'),
                    'progress' => $show->aired_episodes > 0
                        ? (int) min(100, round(($show->seen_episodes / max(1, $show->aired_episodes)) * 100))
                        : 0,
                ];
            })
            ->values()
            ->all();

        return ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function history(User $user, array $filters): array
    {
        $page = $this->page($filters);
        $perPage = $this->perPage($filters);
        $history = $this->historyQuery($user, $filters);
        $total = (clone $history)->count();
        $rows = $history
            ->orderByDesc('watched_at')
            ->orderByDesc('watch_id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): array => $this->historyRow($row));

        return [
            'items' => $rows->values()->all(),
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function search(User $user, array $filters): array
    {
        $query = trim((string) ($filters['query'] ?? $filters['search'] ?? ''));

        if ($query === '') {
            return ['movies' => [], 'shows' => [], 'episodes' => []];
        }

        $movies = Movie::forUser($user)
            ->where('title', 'like', '%'.$query.'%')
            ->orderBy('title')
            ->limit(8)
            ->get();
        $shows = Show::forUser($user)
            ->where('title', 'like', '%'.$query.'%')
            ->orderBy('title')
            ->limit(8)
            ->get();
        $episodes = Episode::forUser($user)
            ->with('show')
            ->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', '%'.$query.'%')
                    ->orWhereHas('show', fn (Builder $showQuery) => $showQuery->where('title', 'like', '%'.$query.'%'));
            })
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->limit(12)
            ->get();

        return [
            'movies' => $this->movieCards($user, $movies),
            'shows' => $this->showCards($user, $shows),
            'episodes' => $this->episodeCards($user, $episodes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function movieCard(User $user, Movie $movie, ?array $context = null): array
    {
        $context ??= $this->movieContext($user, new EloquentCollection([$movie]));
        $watch = $context['watches'][$movie->id] ?? null;

        return [
            'id' => $movie->id,
            'movieId' => $movie->id,
            'kind' => 'movie',
            'title' => $movie->title,
            'poster' => $this->posterFor($movie, $movie->poster_url),
            'backdrop' => $this->backdropFor($movie, $movie->poster_url),
            'year' => $this->yearFromDate($movie->release_date),
            'runtime' => (int) $movie->runtime,
            'watched' => $watch !== null,
            'watchedCount' => (int) ($watch['count'] ?? 0),
            'status' => $movie->is_to_watch ? 'watchlist' : ($watch ? 'watched' : 'library'),
            'latestWatchedAt' => $watch['latest'] ?? null,
            'rating' => $context['ratings'][$movie->id] ?? null,
            'hasNote' => isset($context['notes'][$movie->id]),
            'providerLinked' => isset($context['links'][$movie->id]),
            'metadataStatus' => $this->metadataStatus($movie),
            'subtitle' => 'Movie',
            'meta' => $this->movieMeta($movie),
            'progress' => $watch ? 100 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showCard(User $user, Show $show, ?array $context = null): array
    {
        $context ??= $this->showContext($user, new EloquentCollection([$show]));
        $progress = $show->aired_episodes > 0
            ? (int) min(100, round(($show->seen_episodes / max(1, $show->aired_episodes)) * 100))
            : ($show->seen_episodes > 0 ? 100 : 0);

        return [
            'id' => $show->id,
            'showId' => $show->id,
            'kind' => 'show',
            'title' => $show->title,
            'poster' => $this->posterFor($show, $show->poster_url),
            'backdrop' => $this->backdropFor($show, $show->fanart_url),
            'progress' => $progress,
            'watchedEpisodes' => (int) $show->seen_episodes,
            'airedEpisodes' => (int) $show->aired_episodes,
            'watched' => $show->seen_episodes > 0,
            'status' => $show->followed ? 'followed' : ($show->seen_episodes > 0 ? 'watched' : 'library'),
            'latestWatchedAt' => ($context['watches'][$show->id] ?? null) ?: $show->latest_seen_at?->toIso8601String(),
            'rating' => $context['ratings'][$show->id] ?? null,
            'hasNote' => isset($context['notes'][$show->id]),
            'providerLinked' => isset($context['links'][$show->id]),
            'metadataStatus' => $this->metadataStatus($show),
            'subtitle' => $show->followed ? 'Followed show' : 'TV show',
            'meta' => ((int) $show->seen_episodes).'/'.((int) $show->aired_episodes).' watched',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function episodeCard(User $user, Episode $episode, ?array $context = null): array
    {
        $episode->loadMissing('show');
        $context ??= $this->episodeContext($user, new EloquentCollection([$episode]));
        $watch = $context['watches'][$episode->id] ?? null;

        return [
            'id' => $episode->id,
            'episodeId' => $episode->id,
            'showId' => $episode->show_id,
            'kind' => 'episode',
            'title' => $this->episodeTitle($episode),
            'showTitle' => $episode->show?->title,
            'subtitle' => $this->episodeCode($episode),
            'meta' => trim(($episode->show?->title ? $episode->show->title.' - ' : '').$this->episodeCode($episode), ' -'),
            'poster' => $this->posterFor($episode, $episode->show?->poster_url),
            'backdrop' => $this->backdropFor($episode, $episode->show?->fanart_url),
            'seasonNumber' => (int) $episode->season_number,
            'episodeNumber' => (int) $episode->episode_number,
            'runtime' => (int) $episode->runtime,
            'watched' => $watch !== null,
            'watchedCount' => (int) ($watch['count'] ?? 0),
            'latestWatchedAt' => $watch['latest'] ?? null,
            'rating' => $context['ratings'][$episode->id] ?? null,
            'hasNote' => isset($context['notes'][$episode->id]),
            'providerLinked' => isset($context['links'][$episode->id]),
            'metadataStatus' => $this->metadataStatus($episode),
            'progress' => $watch ? 100 : 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function movieCards(User $user, EloquentCollection $movies): array
    {
        $context = $this->movieContext($user, $movies);

        return $movies->map(fn (Movie $movie): array => $this->movieCard($user, $movie, $context))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function showCards(User $user, EloquentCollection $shows): array
    {
        $context = $this->showContext($user, $shows);

        return $shows->map(fn (Show $show): array => $this->showCard($user, $show, $context))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function episodeCards(User $user, EloquentCollection $episodes): array
    {
        $context = $this->episodeContext($user, $episodes);

        return $episodes->map(fn (Episode $episode): array => $this->episodeCard($user, $episode, $context))->values()->all();
    }

    /** @return array<string, array<int, mixed>> */
    private function movieContext(User $user, EloquentCollection $movies): array
    {
        $ids = $movies->modelKeys();
        if ($ids === []) {
            return $this->emptyCardContext();
        }

        $watches = MovieWatch::forUser($user)->whereIn('movie_id', $ids)->get(['movie_id', 'watched_at', 'watch_count'])
            ->groupBy('movie_id')
            ->map(fn ($rows): array => [
                'latest' => $rows->max('watched_at')?->toIso8601String(),
                'count' => $rows->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count)),
            ])->all();

        return [
            'watches' => $watches,
            'ratings' => $this->ratingsFor($user, 'movie', $ids),
            'notes' => $this->notesFor($user, 'movie', $ids),
            'links' => $this->linksFor($user, 'movie_id', $ids),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function showContext(User $user, EloquentCollection $shows): array
    {
        $ids = $shows->modelKeys();
        if ($ids === []) {
            return $this->emptyCardContext();
        }

        $watches = EpisodeWatch::forUser($user)->whereIn('show_id', $ids)->get(['show_id', 'watched_at'])
            ->groupBy('show_id')
            ->map(fn ($rows): ?string => $rows->max('watched_at')?->toIso8601String())
            ->all();
        $directLinks = $this->linksFor($user, 'show_id', $ids);
        $episodeLinkIds = $this->validMediaLinks($user)
            ->whereHas('episode', fn (Builder $query) => $query->forUser($user)->whereIn('show_id', $ids))
            ->pluck('episode_id');
        $episodeLinkedShows = Episode::forUser($user)
            ->whereIn('id', $episodeLinkIds)
            ->pluck('show_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();

        return [
            'watches' => $watches,
            'ratings' => $this->ratingsFor($user, 'show', $ids),
            'notes' => $this->notesFor($user, 'show', $ids),
            'links' => $directLinks + $episodeLinkedShows,
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function episodeContext(User $user, EloquentCollection $episodes): array
    {
        $ids = $episodes->modelKeys();
        if ($ids === []) {
            return $this->emptyCardContext();
        }

        $watches = EpisodeWatch::forUser($user)->whereIn('episode_id', $ids)->get(['episode_id', 'watched_at'])
            ->groupBy('episode_id')
            ->map(fn ($rows): array => [
                'latest' => $rows->max('watched_at')?->toIso8601String(),
                'count' => $rows->count(),
            ])->all();

        return [
            'watches' => $watches,
            'ratings' => $this->ratingsFor($user, 'episode', $ids),
            'notes' => $this->notesFor($user, 'episode', $ids),
            'links' => $this->linksFor($user, 'episode_id', $ids),
        ];
    }

    /** @param list<int> $ids @return array<int, int> */
    private function ratingsFor(User $user, string $mediaType, array $ids): array
    {
        return Rating::forUser($user)->where('media_type', $mediaType)->whereIn('media_id', $ids)
            ->pluck('rating', 'media_id')->map(fn (mixed $rating): int => (int) $rating)->all();
    }

    /** @param list<int> $ids @return array<int, bool> */
    private function notesFor(User $user, string $mediaType, array $ids): array
    {
        return Note::forUser($user)->where('media_type', $mediaType)->whereIn('media_id', $ids)
            ->pluck('media_id')->mapWithKeys(fn (int $id): array => [$id => true])->all();
    }

    /** @param list<int> $ids @return array<int, bool> */
    private function linksFor(User $user, string $column, array $ids): array
    {
        return $this->validMediaLinks($user)->whereIn($column, $ids)->pluck($column)
            ->mapWithKeys(fn (int $id): array => [$id => true])->all();
    }

    private function validMediaLinks(User $user): Builder
    {
        return MediaLink::forUser($user)
            ->whereHas('sourceItem', fn (Builder $query) => $query
                ->forUser($user)
                ->whereHas('source', fn (Builder $sourceQuery) => $sourceQuery->forUser($user)));
    }

    /** @return array<string, array<int, mixed>> */
    private function emptyCardContext(): array
    {
        return ['watches' => [], 'ratings' => [], 'notes' => [], 'links' => []];
    }

    /**
     * @param  Builder<Movie|Show>  $query
     * @return array{0:EloquentCollection<int, Movie|Show>,1:array<string, int|bool>}
     */
    private function paginate(Builder $query, array $filters): array
    {
        $page = $this->page($filters);
        $perPage = $this->perPage($filters);
        $total = (clone $query)->count();
        $items = $query
            ->forPage($page, $perPage)
            ->get();

        return [$items, $this->pagination($page, $perPage, $total)];
    }

    /**
     * @return array<string, int|bool>
     */
    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'hasMore' => ($page * $perPage) < $total,
        ];
    }

    private function page(array $filters): int
    {
        return max(1, (int) ($filters['page'] ?? 1));
    }

    private function perPage(array $filters): int
    {
        return max(1, min(60, (int) ($filters['per_page'] ?? 24)));
    }

    private function applySearch(Builder $query, mixed $search, string $column): void
    {
        $term = trim((string) $search);

        if ($term !== '') {
            $query->where($column, 'like', '%'.$term.'%');
        }
    }

    private function whereHasAnnotation(Builder $query, User $user, string $mediaType, string $model): void
    {
        $table = (new $model)->getTable();
        $query->whereExists(fn ($sub) => $sub
            ->selectRaw('1')
            ->from($table)
            ->whereColumn($table.'.media_id', $query->getModel()->getTable().'.id')
            ->where($table.'.user_id', $user->id)
            ->where($table.'.media_type', $mediaType));
    }

    private function sortMovies(Builder $query, User $user, string $sort): void
    {
        match ($sort) {
            'title' => $query->orderBy('title'),
            'rating' => $query->orderByDesc($this->ratingSubquery($user, 'movie'))->orderBy('title'),
            'year' => $query->orderByDesc('release_date')->orderBy('title'),
            'newest_added' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query->orderByDesc(MovieWatch::selectRaw('max(watched_at)')
                ->whereColumn('movie_watches.movie_id', 'movies.id')
                ->where('movie_watches.user_id', $user->id))
                ->orderBy('title'),
        };
    }

    private function sortShows(Builder $query, User $user, string $sort): void
    {
        match ($sort) {
            'title' => $query->orderBy('title'),
            'rating' => $query->orderByDesc($this->ratingSubquery($user, 'show'))->orderBy('title'),
            'progress' => $query->orderByRaw('CASE WHEN aired_episodes > 0 THEN CAST(seen_episodes AS REAL) / aired_episodes ELSE seen_episodes END DESC')->orderBy('title'),
            'newest_added' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query->orderByDesc(EpisodeWatch::selectRaw('max(watched_at)')
                ->whereColumn('episode_watches.show_id', 'shows.id')
                ->where('episode_watches.user_id', $user->id))
                ->orderByDesc('latest_seen_at')
                ->orderBy('title'),
        };
    }

    private function ratingSubquery(User $user, string $mediaType): Builder
    {
        return Rating::select('rating')
            ->whereColumn('ratings.media_id', $mediaType === 'movie' ? 'movies.id' : 'shows.id')
            ->where('ratings.user_id', $user->id)
            ->where('ratings.media_type', $mediaType)
            ->limit(1);
    }

    private function historyQuery(User $user, array $filters): QueryBuilder
    {
        $type = $filters['type'] ?? 'all';
        $queries = [];

        if ($type === 'all' || $type === 'movie') {
            $queries[] = $this->movieHistoryQuery($user, $filters);
        }

        if ($type === 'all' || $type === 'episode') {
            $queries[] = $this->episodeHistoryQuery($user, $filters);
        }

        if ($queries === []) {
            $queries[] = $this->movieHistoryQuery($user, ['type' => 'movie', 'search' => '__mediahub_no_results__']);
        }

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'history');
    }

    private function movieHistoryQuery(User $user, array $filters): QueryBuilder
    {
        $query = DB::table('movie_watches')
            ->join('movies', function ($join) use ($user): void {
                $join->on('movie_watches.movie_id', '=', 'movies.id')
                    ->where('movies.user_id', $user->id);
            })
            ->leftJoin('ratings as movie_ratings', function ($join) use ($user): void {
                $join->on('movie_watches.movie_id', '=', 'movie_ratings.media_id')
                    ->where('movie_ratings.user_id', $user->id)
                    ->where('movie_ratings.media_type', 'movie');
            })
            ->where('movie_watches.user_id', $user->id)
            ->whereNotNull('movie_watches.watched_at')
            ->select([
                'movie_watches.id as watch_id',
                DB::raw("'movie' as kind"),
                'movie_watches.movie_id as movie_id',
                DB::raw('null as episode_id'),
                DB::raw('null as show_id'),
                'movies.title as title',
                DB::raw('null as show_title'),
                DB::raw("'Movie' as subtitle"),
                DB::raw('null as season_number'),
                DB::raw('null as episode_number'),
                'movie_watches.watched_at as watched_at',
                'movie_watches.source as source',
                'movie_watches.watch_count as watch_count',
                'movie_ratings.rating as rating',
                'movies.poster_url as poster_url',
                'movies.poster_path as poster_path',
                'movies.backdrop_path as backdrop_path',
            ]);

        $this->applyHistoryDateFilters($query, $filters, 'movie_watches.watched_at');

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $query->where('movies.title', 'like', '%'.$term.'%');
        }

        return $query;
    }

    private function episodeHistoryQuery(User $user, array $filters): QueryBuilder
    {
        $query = DB::table('episode_watches')
            ->join('episodes', function ($join) use ($user): void {
                $join->on('episode_watches.episode_id', '=', 'episodes.id')
                    ->where('episodes.user_id', $user->id);
            })
            ->join('shows', function ($join) use ($user): void {
                $join->on('episode_watches.show_id', '=', 'shows.id')
                    ->where('shows.user_id', $user->id);
            })
            ->leftJoin('ratings as episode_ratings', function ($join) use ($user): void {
                $join->on('episode_watches.episode_id', '=', 'episode_ratings.media_id')
                    ->where('episode_ratings.user_id', $user->id)
                    ->where('episode_ratings.media_type', 'episode');
            })
            ->where('episode_watches.user_id', $user->id)
            ->whereNotNull('episode_watches.watched_at')
            ->select([
                'episode_watches.id as watch_id',
                DB::raw("'episode' as kind"),
                DB::raw('null as movie_id'),
                'episode_watches.episode_id as episode_id',
                'episode_watches.show_id as show_id',
                'episodes.title as title',
                'shows.title as show_title',
                DB::raw("'Episode' as subtitle"),
                'episodes.season_number as season_number',
                'episodes.episode_number as episode_number',
                'episode_watches.watched_at as watched_at',
                'episode_watches.source as source',
                DB::raw('1 as watch_count'),
                'episode_ratings.rating as rating',
                'shows.poster_url as poster_url',
                DB::raw('coalesce(episodes.poster_path, shows.poster_path) as poster_path'),
                DB::raw('coalesce(episodes.backdrop_path, shows.backdrop_path) as backdrop_path'),
            ]);

        $this->applyHistoryDateFilters($query, $filters, 'episode_watches.watched_at');

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $query->where(function (QueryBuilder $builder) use ($term): void {
                $builder->where('episodes.title', 'like', '%'.$term.'%')
                    ->orWhere('shows.title', 'like', '%'.$term.'%');
            });
        }

        return $query;
    }

    private function applyHistoryDateFilters(QueryBuilder $query, array $filters, string $column): void
    {
        if (! empty($filters['date_from'])) {
            $query->where($column, '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where($column, '<=', $filters['date_to']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(object $row): array
    {
        if ($row->kind === 'movie') {
            return [
                'id' => 'movie-'.$row->watch_id,
                'watchId' => (int) $row->watch_id,
                'kind' => 'movie',
                'movieId' => (int) $row->movie_id,
                'title' => $row->title ?: 'Unknown movie',
                'subtitle' => 'Movie',
                'watchedAt' => $this->isoDate($row->watched_at),
                'source' => $row->source ?: 'archive',
                'watchCount' => max(1, (int) $row->watch_count),
                'rating' => $row->rating !== null ? (int) $row->rating : null,
                'poster' => $this->historyImage($row),
            ];
        }

        $code = $this->historyEpisodeCode($row);

        return [
            'id' => 'episode-'.$row->watch_id,
            'watchId' => (int) $row->watch_id,
            'kind' => 'episode',
            'episodeId' => (int) $row->episode_id,
            'showId' => (int) $row->show_id,
            'title' => $row->title ?: 'Unknown episode',
            'showTitle' => $row->show_title,
            'subtitle' => $code,
            'meta' => trim(($row->show_title ? $row->show_title.' - ' : '').$code, ' -'),
            'watchedAt' => $this->isoDate($row->watched_at),
            'source' => $row->source ?: 'archive',
            'watchCount' => 1,
            'rating' => $row->rating !== null ? (int) $row->rating : null,
            'poster' => $this->historyImage($row),
        ];
    }

    private function metadataStatus(mixed $media): string
    {
        return $media->metadata_refreshed_at ? 'enriched' : 'local';
    }

    private function movieMeta(Movie $movie): string
    {
        $bits = array_filter([
            $this->yearFromDate($movie->release_date),
            $movie->runtime > 0 ? $movie->runtime.' min' : null,
        ]);

        return $bits ? implode(' - ', $bits) : 'Movie';
    }

    private function posterFor(mixed $media, ?string $fallback = ''): string
    {
        return $this->metadata->imageUrl($media->poster_path ?? null) ?: ($fallback ?? '');
    }

    private function backdropFor(mixed $media, ?string $fallback = ''): string
    {
        return $this->metadata->imageUrl($media->backdrop_path ?? null, 'w780') ?: ($fallback ?? '');
    }

    private function yearFromDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y');
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('Y');
        } catch (Throwable) {
            return null;
        }
    }

    private function isoDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function historyImage(object $row): string
    {
        return $this->metadata->imageUrl($row->poster_path ?? null) ?: ((string) ($row->poster_url ?? ''));
    }

    private function historyEpisodeCode(object $row): string
    {
        if ($row->season_number && $row->episode_number) {
            return 'S'.str_pad((string) $row->season_number, 2, '0', STR_PAD_LEFT)
                .'E'.str_pad((string) $row->episode_number, 2, '0', STR_PAD_LEFT);
        }

        return 'Episode';
    }

    private function episodeTitle(?Episode $episode): string
    {
        if (! $episode) {
            return 'Unknown episode';
        }

        return trim((string) $episode->title) ?: 'Episode '.((int) $episode->episode_number ?: $episode->id);
    }

    private function episodeCode(Episode $episode): string
    {
        if ($episode->season_number && $episode->episode_number) {
            return 'S'.str_pad((string) $episode->season_number, 2, '0', STR_PAD_LEFT)
                .'E'.str_pad((string) $episode->episode_number, 2, '0', STR_PAD_LEFT);
        }

        return 'Episode';
    }
}
