<?php

namespace App\Services;

use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\PlaybackProgress;
use App\Models\PlaybackSourceItem;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MediaLibraryService
{
    /**
     * @return array<string, int>
     */
    public function statsFor(User $user): array
    {
        $episodeMinutes = (int) EpisodeWatch::forUser($user)->sum('runtime');
        $movieMinutes = (int) MovieWatch::forUser($user)->get(['runtime', 'watch_count'])
            ->sum(fn (MovieWatch $watch): int => $watch->runtime * max(1, $watch->watch_count));
        $movieWatchEvents = (int) MovieWatch::forUser($user)->get(['watch_count'])
            ->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count));
        $manualMovieWatches = (int) MovieWatch::forUser($user)->where('source', 'manual')->get(['watch_count'])
            ->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count));
        $autoMovieWatches = (int) MovieWatch::forUser($user)->where('source', 'provider')->get(['watch_count'])
            ->sum(fn (MovieWatch $watch): int => max(1, $watch->watch_count));
        $manualWatchesCount = $manualMovieWatches
            + EpisodeWatch::forUser($user)->where('source', 'manual')->count();
        $autoTrackedWatchesCount = $autoMovieWatches
            + EpisodeWatch::forUser($user)->where('source', 'provider')->count();

        return [
            'episodesWatched' => EpisodeWatch::forUser($user)->count(),
            'moviesWatched' => $movieWatchEvents,
            'hoursWatched' => (int) round(($episodeMinutes + $movieMinutes) / 60),
            'showsFollowed' => Show::forUser($user)->followed()->count(),
            'manualWatchesCount' => $manualWatchesCount,
            'autoTrackedWatchesCount' => $autoTrackedWatchesCount,
            'linkedProviderItemsCount' => MediaLink::forUser($user)
                ->whereHas('sourceItem', fn (Builder $query) => $query
                    ->forUser($user)
                    ->whereHas('source', fn (Builder $sourceQuery) => $sourceQuery->forUser($user)))
                ->count(),
            'unlinkedProviderItemsCount' => PlaybackSourceItem::forUser($user)
                ->whereHas('source', fn (Builder $query) => $query->forUser($user)->active())
                ->whereDoesntHave('mediaLink', fn (Builder $query) => $query->forUser($user))
                ->count(),
            'unsyncedSourceOnlyProgressCount' => PlaybackProgress::forUser($user)
                ->whereNull('movie_id')
                ->whereNull('episode_id')
                ->count(),
            'ratingsCount' => Rating::forUser($user)->count(),
            'notesCount' => Note::forUser($user)->count(),
        ];
    }
}
