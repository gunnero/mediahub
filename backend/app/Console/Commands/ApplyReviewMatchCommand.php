<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Services\KalveriAIMediaMatcherService;
use Illuminate\Console\Command;

class ApplyReviewMatchCommand extends Command
{
    protected $signature = 'mediahub:apply-review-match
        {episode_id}
        {--season= : Corrected TMDB season number}
        {--episode= : Corrected TMDB episode number}';

    protected $description = 'Apply a confirmed metadata review episode match.';

    public function handle(KalveriAIMediaMatcherService $matcher): int
    {
        $episode = Episode::with('user')->find($this->argument('episode_id'));

        if (! $episode || ! $episode->user) {
            $this->error('Apply review match failed: episode was not found.');

            return self::FAILURE;
        }

        $season = $this->option('season');
        $episodeNumber = $this->option('episode');

        if (! is_numeric($season) || (int) $season < 1) {
            $this->error('Apply review match failed: --season must be a positive integer.');

            return self::FAILURE;
        }

        if (! is_numeric($episodeNumber) || (int) $episodeNumber < 1) {
            $this->error('Apply review match failed: --episode must be a positive integer.');

            return self::FAILURE;
        }

        $summary = $matcher->applyReviewMatch($episode->user, $episode, (int) $season, (int) $episodeNumber);
        $episode->refresh();

        $this->line('episode_id: '.$episode->id);
        $this->line('tmdb_id: '.($episode->tmdb_id ?: 'none'));
        $this->line('metadata_review_status: '.($episode->metadata_review_status ?: 'none'));

        foreach ($summary as $key => $value) {
            $this->line($key.': '.$value);
        }

        return $summary['enriched'] === 1 ? self::SUCCESS : self::FAILURE;
    }
}
