<?php

namespace App\Services;

use App\Enums\MetadataReviewStatus;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EpisodeCatalogService
{
    public function __construct(
        private readonly TMDBClientService $tmdb,
        private readonly MediaMetadataService $metadata,
        private readonly CanonicalWatchHistoryService $history,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{shows_planned:int,shows_synced:int,seasons_synced:int,episodes_created:int,episodes_updated:int,skipped:int,failed:int}
     */
    public function syncUser(User $user, array $options = []): array
    {
        $summary = $this->summary();
        $limit = max(0, (int) ($options['limit_shows'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 0));
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $query = Show::forUser($user)->whereNotNull('tmdb_id')->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $shows = $query->get();
        foreach ($shows as $show) {
            $showSummary = $this->syncShow($user, $show, [
                'sleep_ms' => $sleepMs,
                'dry_run' => $dryRun,
            ]);
            foreach ($showSummary as $key => $value) {
                $summary[$key] += $value;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{shows_planned:int,shows_synced:int,seasons_synced:int,episodes_created:int,episodes_updated:int,skipped:int,failed:int}
     */
    public function syncShow(User $user, Show $show, array $options = []): array
    {
        $summary = $this->summary();
        if ((int) $show->user_id !== (int) $user->id) {
            $summary['skipped']++;

            return $summary;
        }

        $summary['shows_planned'] = 1;
        if (! $show->tmdb_id || ! $this->tmdb->enabled()) {
            $summary['skipped']++;

            return $summary;
        }

        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 0));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $details = $this->tmdb->getShow((int) $show->tmdb_id);
        if (! $details) {
            $summary['failed']++;

            return $summary;
        }

        $seasons = collect($details['seasons'] ?? [])
            ->filter(fn (mixed $season): bool => is_array($season)
                && (int) ($season['season_number'] ?? 0) > 0
                && (int) ($season['episode_count'] ?? 0) > 0)
            ->sortBy('season_number')
            ->values();
        $showSucceeded = false;

        foreach ($seasons as $season) {
            $payload = $this->tmdb->getSeason((int) $show->tmdb_id, (int) $season['season_number']);
            if (! is_array($payload)) {
                $summary['failed']++;

                continue;
            }

            $summary['seasons_synced']++;
            $showSucceeded = true;
            foreach ($payload['episodes'] ?? [] as $episodeDetails) {
                if (! is_array($episodeDetails)) {
                    $summary['skipped']++;

                    continue;
                }

                $seasonNumber = (int) ($episodeDetails['season_number'] ?? $season['season_number']);
                $episodeNumber = (int) ($episodeDetails['episode_number'] ?? 0);
                if ($seasonNumber <= 0 || $episodeNumber <= 0 || ! is_numeric($episodeDetails['id'] ?? null)) {
                    $summary['skipped']++;

                    continue;
                }

                $existing = Episode::forUser($user)
                    ->where('show_id', $show->id)
                    ->where('season_number', $seasonNumber)
                    ->where('episode_number', $episodeNumber)
                    ->first();
                $existing ??= Episode::forUser($user)->where('tmdb_id', (int) $episodeDetails['id'])->first();

                if ($existing && in_array($existing->metadata_review_status, [
                    MetadataReviewStatus::Ignored->value,
                    MetadataReviewStatus::ManuallyMatched->value,
                ], true)) {
                    $summary['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $summary[$existing ? 'episodes_updated' : 'episodes_created']++;

                    continue;
                }

                DB::transaction(function () use ($episodeDetails, $episodeNumber, $existing, $seasonNumber, $show, $user, &$summary): void {
                    $episode = $existing ?: Episode::create([
                        'user_id' => $user->id,
                        'show_id' => $show->id,
                        'external_source' => 'tmdb',
                        'external_id' => (string) $episodeDetails['id'],
                        'season_number' => $seasonNumber,
                        'episode_number' => $episodeNumber,
                        'title' => (string) ($episodeDetails['name'] ?? ''),
                    ]);
                    $this->metadata->applyCatalogEpisode($episode, $episodeDetails);
                    $summary[$existing ? 'episodes_updated' : 'episodes_created']++;
                });
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        if ($showSucceeded) {
            $summary['shows_synced']++;
            if (! $dryRun) {
                $this->history->recalculateShow($user, $show->refresh());
            }
        }

        return $summary;
    }

    /** @return array{shows_planned:int,shows_synced:int,seasons_synced:int,episodes_created:int,episodes_updated:int,skipped:int,failed:int} */
    private function summary(): array
    {
        return [
            'shows_planned' => 0,
            'shows_synced' => 0,
            'seasons_synced' => 0,
            'episodes_created' => 0,
            'episodes_updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
    }
}
