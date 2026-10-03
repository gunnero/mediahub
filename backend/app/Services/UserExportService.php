<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaList;
use App\Models\MediaPreference;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use InvalidArgumentException;

class UserExportService
{
    /** @return array<string, mixed> */
    public function payload(User $user): array
    {
        return [
            'schema' => 'mediahub-user-export-v1',
            'exported_at' => now()->toIso8601String(),
            'profile' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'movies' => $this->movieRows($user),
            'shows' => $this->showRows($user),
            'episodes' => $this->episodeRows($user),
            'movie_watches' => $this->movieWatchRows($user),
            'episode_watches' => $this->episodeWatchRows($user),
            'ratings' => $this->ratingRows($user),
            'notes' => $this->noteRows($user),
            'library_preferences' => MediaPreference::where('user_id', $user->id)->orderBy('id')->get(['media_type', 'media_id', 'pinned', 'status', 'tags'])->toArray(),
            'lists' => MediaList::forUser($user)->with(['items' => fn ($query) => $query->forUser($user)])->get()->map(fn (MediaList $list): array => [
                'id' => $list->id,
                'name' => $list->name,
                'description' => $list->description,
                'visibility' => $list->visibility,
                'rules' => $list->rules,
                'cover_style' => $list->cover_style,
                'items' => $list->items->map(fn ($item): array => ['media_type' => $item->media_type, 'media_id' => $item->media_id, 'position' => $item->position])->all(),
            ])->all(),
        ];
    }

    /** @return array{filename:string,headers:list<string>,rows:list<array<int|string|null>>} */
    public function csv(User $user, string $dataset): array
    {
        $rows = match ($dataset) {
            'movies' => $this->movieRows($user),
            'shows' => $this->showRows($user),
            'episodes' => $this->episodeRows($user),
            'movie-watches' => $this->movieWatchRows($user),
            'episode-watches' => $this->episodeWatchRows($user),
            'ratings' => $this->ratingRows($user),
            'notes' => $this->noteRows($user),
            default => throw new InvalidArgumentException('unsupported_export_dataset'),
        };
        $headers = $rows !== [] ? array_keys($rows[0]) : $this->emptyHeaders($dataset);

        return [
            'filename' => 'mediahub-'.$dataset.'-'.now()->format('Ymd-His').'.csv',
            'headers' => $headers,
            'rows' => array_map(fn (array $row): array => array_map($this->safeCsvCell(...), array_values($row)), $rows),
        ];
    }

    private function movieRows(User $user): array
    {
        return Movie::forUser($user)->orderBy('id')->get()->map(fn (Movie $movie): array => ['id' => $movie->id, 'title' => $movie->title, 'tmdb_id' => $movie->tmdb_id, 'imdb_id' => $movie->imdb_id, 'release_date' => $movie->release_date?->toDateString(), 'runtime' => $movie->runtime, 'watchlist' => $movie->is_to_watch])->all();
    }

    private function showRows(User $user): array
    {
        return Show::forUser($user)->orderBy('id')->get()->map(fn (Show $show): array => ['id' => $show->id, 'title' => $show->title, 'tmdb_id' => $show->tmdb_id, 'imdb_id' => $show->imdb_id, 'first_air_date' => $show->first_air_date?->toDateString(), 'followed' => $show->followed, 'seen_episodes' => $show->seen_episodes, 'aired_episodes' => $show->aired_episodes])->all();
    }

    private function episodeRows(User $user): array
    {
        return Episode::forUser($user)->orderBy('id')->get()->map(fn (Episode $episode): array => ['id' => $episode->id, 'show_id' => $episode->show_id, 'season_number' => $episode->season_number, 'episode_number' => $episode->episode_number, 'title' => $episode->title, 'tmdb_id' => $episode->tmdb_id, 'air_date' => $episode->air_date?->toDateString(), 'runtime' => $episode->runtime])->all();
    }

    private function movieWatchRows(User $user): array
    {
        return MovieWatch::forUser($user)
            ->whereHas('movie', fn ($query) => $query->forUser($user))
            ->orderBy('id')->get()
            ->map(fn (MovieWatch $watch): array => ['id' => $watch->id, 'movie_id' => $watch->movie_id, 'watched_at' => $watch->watched_at?->toIso8601String(), 'runtime' => $watch->runtime, 'watch_count' => $watch->watch_count, 'source' => $watch->source])->all();
    }

    private function episodeWatchRows(User $user): array
    {
        return EpisodeWatch::forUser($user)
            ->whereHas('episode', fn ($query) => $query->forUser($user))
            ->whereHas('show', fn ($query) => $query->forUser($user))
            ->orderBy('id')->get()
            ->map(fn (EpisodeWatch $watch): array => ['id' => $watch->id, 'show_id' => $watch->show_id, 'episode_id' => $watch->episode_id, 'watched_at' => $watch->watched_at?->toIso8601String(), 'runtime' => $watch->runtime, 'source' => $watch->source])->all();
    }

    private function ratingRows(User $user): array
    {
        return Rating::forUser($user)->orderBy('id')->get()->map(fn (Rating $rating): array => ['id' => $rating->id, 'media_type' => $rating->media_type, 'media_id' => $rating->media_id, 'rating' => $rating->rating])->all();
    }

    private function noteRows(User $user): array
    {
        return Note::forUser($user)->orderBy('id')->get()->map(fn (Note $note): array => ['id' => $note->id, 'media_type' => $note->media_type, 'media_id' => $note->media_id, 'body' => $note->body])->all();
    }

    /** @return list<string> */
    private function emptyHeaders(string $dataset): array
    {
        return match ($dataset) {
            'movies' => ['id', 'title', 'tmdb_id', 'imdb_id', 'release_date', 'runtime', 'watchlist'],
            'shows' => ['id', 'title', 'tmdb_id', 'imdb_id', 'first_air_date', 'followed', 'seen_episodes', 'aired_episodes'],
            'episodes' => ['id', 'show_id', 'season_number', 'episode_number', 'title', 'tmdb_id', 'air_date', 'runtime'],
            'movie-watches' => ['id', 'movie_id', 'watched_at', 'runtime', 'watch_count', 'source'],
            'episode-watches' => ['id', 'show_id', 'episode_id', 'watched_at', 'runtime', 'source'],
            'ratings' => ['id', 'media_type', 'media_id', 'rating'],
            'notes' => ['id', 'media_type', 'media_id', 'body'],
        };
    }

    private function safeCsvCell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
