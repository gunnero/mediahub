<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\MediaPreference;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PersonalLibraryService
{
    public function owned(User $user, string $type, int $id): Movie|Show
    {
        abort_unless(in_array($type, ['movie', 'show'], true), 404);

        return ($type === 'movie' ? Movie::query() : Show::query())->forUser($user)->findOrFail($id);
    }

    public function apply(Builder $query, User $user, string $type, array $filters): void
    {
        $table = $query->getModel()->getTable();
        if (filled($filters['tag'] ?? null)) {
            $query->whereIn($table.'.id', MediaPreference::where('user_id', $user->id)->where('media_type', $type)->whereJsonContains('tags', (string) $filters['tag'])->select('media_id'));
        }
        if (in_array($filters['personal_status'] ?? '', ['active', 'paused', 'dropped'], true)) {
            $status = $filters['personal_status'];
            if ($status === 'active') {
                $query->whereNotIn($table.'.id', MediaPreference::where('user_id', $user->id)->where('media_type', $type)->whereIn('status', ['paused', 'dropped'])->select('media_id'));
            } else {
                $query->whereIn($table.'.id', MediaPreference::where('user_id', $user->id)->where('media_type', $type)->where('status', $status)->select('media_id'));
            }
        }
        if (filled($filters['genre'] ?? null)) {
            $query->where(fn ($q) => $q->whereJsonContains('genres', (string) $filters['genre'])->orWhereJsonContains('genres', ['name' => (string) $filters['genre']]));
        }
        if ((int) ($filters['max_runtime'] ?? 0) > 0) {
            $query->whereBetween('runtime', [1, min(1440, (int) $filters['max_runtime'])]);
        }
        if (filled($filters['min_rating'] ?? null)) {
            $query->where('vote_average', '>=', max(0, min(10, (float) $filters['min_rating'])));
        }
        if (filled($filters['year'] ?? null)) {
            $query->whereYear($type === 'movie' ? 'release_date' : 'first_air_date', (int) $filters['year']);
        }
        if (filter_var($filters['unwatched'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereDoesntHave($type === 'movie' ? 'watches' : 'episodeWatches', fn ($q) => $q->forUser($user)->watched());
        }
        if ($type === 'show' && (int) ($filters['max_remaining'] ?? 0) > 0) {
            $query->whereRaw('aired_episodes - seen_episodes BETWEEN 1 AND ?', [min(1000, (int) $filters['max_remaining'])]);
        }
    }

    public function queue(User $user, int $minutes = 0): array
    {
        $preferences = MediaPreference::where('user_id', $user->id)->where('media_type', 'show')->get()->keyBy('media_id');
        $shows = Show::forUser($user)->where(function ($q) use ($preferences, $user): void {
            $q->where('followed', true)->orWhereHas('episodeWatches', fn ($w) => $w->forUser($user)->watched())
                ->orWhereIn('id', $preferences->where('pinned', true)->keys());
        })->whereNotIn('id', $preferences->whereIn('status', ['paused', 'dropped'])->keys())
            ->orderByDesc('latest_seen_at')->get()->sortByDesc(fn ($show) => (int) ($preferences->get($show->id)?->pinned ?? false));
        $unseen = Episode::forUser($user)->whereIn('show_id', $shows->pluck('id'))
            ->where('season_number', '>', 0)->where('episode_number', '>', 0)
            ->whereNotNull('air_date')->whereDate('air_date', '<=', now($user->timezone ?: 'UTC')->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('episode_watches')->whereColumn('episode_watches.episode_id', 'episodes.id')->where('episode_watches.user_id', $user->id)->whereNotNull('watched_at'))
            ->orderBy('season_number')->orderBy('episode_number')->get()->groupBy('show_id');
        $metadata = app(MediaMetadataService::class);

        return $shows->map(function ($show) use ($unseen, $preferences, $minutes, $metadata) {
            $episodes = $unseen->get($show->id, collect());
            $next = $episodes->first();
            $runtime = $next?->runtime ?: $show->runtime;
            if (! $next || ($minutes > 0 && (! $runtime || $runtime > $minutes))) {
                return null;
            }

            return ['kind' => 'episode', 'id' => $next->id, 'episodeId' => $next->id, 'showId' => $show->id,
                'title' => $show->title, 'subtitle' => sprintf('S%02dE%02d · %s', $next->season_number, $next->episode_number, $next->title ?: 'Next episode'),
                'poster' => $metadata->imageUrl($show->poster_path) ?: $show->poster_url,
                'runtime' => $runtime, 'remaining' => $episodes->count(),
                'catchUpMinutes' => $episodes->sum(fn ($episode) => $episode->runtime ?: $show->runtime ?: 0),
                'estimated' => $episodes->contains(fn ($episode) => ! $episode->runtime),
                'pinned' => $preferences->get($show->id)?->pinned ?? false];
        })->filter()->values()->all();
    }
}
