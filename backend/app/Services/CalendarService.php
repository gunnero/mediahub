<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CalendarService
{
    public function __construct(private readonly MediaMetadataService $metadata) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function forUser(User $user, array $filters = []): array
    {
        $timezone = $user->timezone ?? config('app.timezone');
        $start = filled($filters['date_from'] ?? null)
            ? CarbonImmutable::parse((string) $filters['date_from'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfMonth();
        $end = filled($filters['date_to'] ?? null)
            ? CarbonImmutable::parse((string) $filters['date_to'], $timezone)->endOfDay()
            : $start->endOfMonth();
        $end = $end->min($start->addMonths(3)->endOfDay());
        $type = in_array($filters['type'] ?? 'all', ['all', 'movies', 'episodes'], true)
            ? (string) ($filters['type'] ?? 'all')
            : 'all';

        $items = collect();

        if ($type !== 'movies') {
            $items = $items->concat(
                Episode::forUser($user)
                    ->with(['show' => fn ($query) => $query->forUser($user)])
                    ->whereDate('air_date', '>=', $start->toDateString())
                    ->whereDate('air_date', '<=', $end->toDateString())
                    ->whereHas('show', fn (Builder $query) => $query
                        ->forUser($user)
                        ->where(fn (Builder $showQuery) => $showQuery->where('followed', true)->orWhere('seen_episodes', '>', 0)))
                    ->orderBy('air_date')
                    ->orderBy('show_id')
                    ->orderBy('season_number')
                    ->orderBy('episode_number')
                    ->get()
                    ->map(fn (Episode $episode): array => [
                        'id' => 'episode-'.$episode->id,
                        'kind' => 'episode',
                        'episodeId' => $episode->id,
                        'showId' => $episode->show_id,
                        'date' => $episode->air_date?->toDateString(),
                        'title' => $episode->show?->title ?? 'Untitled show',
                        'subtitle' => $this->episodeCode($episode).' · '.($episode->title ?: 'Episode'),
                        'poster' => $this->metadata->imageUrl($episode->show?->poster_path) ?: ($episode->show?->poster_url ?? ''),
                        'released' => $episode->air_date?->isPast() || $episode->air_date?->isToday(),
                    ])
            );

            $items = $items->concat($this->nextEpisodeHints($user, $start, $end));
        }

        if ($type !== 'episodes') {
            $items = $items->concat(
                Movie::forUser($user)
                    ->whereDate('release_date', '>=', $start->toDateString())
                    ->whereDate('release_date', '<=', $end->toDateString())
                    ->where('is_to_watch', true)
                    ->orderBy('release_date')
                    ->orderBy('title')
                    ->get()
                    ->map(fn (Movie $movie): array => [
                        'id' => 'movie-'.$movie->id,
                        'kind' => 'movie',
                        'movieId' => $movie->id,
                        'date' => $movie->release_date?->toDateString(),
                        'title' => $movie->title,
                        'subtitle' => 'Movie release',
                        'poster' => $this->metadata->imageUrl($movie->poster_path) ?: ($movie->poster_url ?? ''),
                        'released' => $movie->release_date?->isPast() || $movie->release_date?->isToday(),
                    ])
            );
        }

        $sorted = $items->filter(fn (array $item): bool => filled($item['date']))
            ->sortBy(fn (array $item): string => $item['date'].'|'.$item['title'])
            ->values();

        return [
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'timezone' => $timezone],
            'type' => $type,
            'items' => $sorted->all(),
            'days' => $sorted->groupBy('date')->map->values()->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function nextEpisodeHints(User $user, CarbonImmutable $start, CarbonImmutable $end)
    {
        return Show::forUser($user)
            ->where(fn (Builder $query) => $query->where('followed', true)->orWhere('seen_episodes', '>', 0))
            ->get()
            ->map(function (Show $show) use ($end, $start, $user): ?array {
                $hint = data_get($show->metadata, 'release.next_episode');

                if (! is_array($hint) || blank($hint['air_date'] ?? null)) {
                    return null;
                }

                $date = CarbonImmutable::parse((string) $hint['air_date']);
                if ($date->lt($start) || $date->gt($end)) {
                    return null;
                }

                $season = (int) ($hint['season_number'] ?? 0);
                $episode = (int) ($hint['episode_number'] ?? 0);
                $alreadyLocal = Episode::forUser($user)
                    ->where('show_id', $show->id)
                    ->where('season_number', $season)
                    ->where('episode_number', $episode)
                    ->exists();

                if ($alreadyLocal) {
                    return null;
                }

                return [
                    'id' => 'show-next-'.$show->id.'-'.$date->toDateString(),
                    'kind' => 'show',
                    'releaseKind' => 'episode',
                    'showId' => $show->id,
                    'date' => $date->toDateString(),
                    'title' => $show->title,
                    'subtitle' => sprintf('S%02dE%02d', max(0, $season), max(0, $episode)).' · '.((string) ($hint['name'] ?? 'Episode')),
                    'poster' => $this->metadata->imageUrl($show->poster_path) ?: ($show->poster_url ?? ''),
                    'released' => $date->isPast() || $date->isToday(),
                ];
            })
            ->filter()
            ->values();
    }

    private function episodeCode(Episode $episode): string
    {
        $season = max(0, (int) $episode->season_number);
        $number = max(0, (int) $episode->episode_number);

        return sprintf('S%02dE%02d', $season, $number);
    }
}
