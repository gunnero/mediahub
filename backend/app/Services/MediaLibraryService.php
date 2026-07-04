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
        $movieMinutes = (int) MovieWatch::forUser($user)->sum('runtime');
        $manualWatchesCount = MovieWatch::forUser($user)->where('source', 'manual')->count()
            + EpisodeWatch::forUser($user)->where('source', 'manual')->count();
        $autoTrackedWatchesCount = MovieWatch::forUser($user)->where('source', 'provider')->count()
            + EpisodeWatch::forUser($user)->where('source', 'provider')->count();

        return [
            'episodesWatched' => EpisodeWatch::forUser($user)->count(),
            'moviesWatched' => MovieWatch::forUser($user)->count(),
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
