<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Services\KalveriAIMediaMatcherService;
use Illuminate\Console\Command;

class AiMatchReviewEpisodeCommand extends Command
{
    protected $signature = 'mediahub:ai-match-review-episode {episode_id}';

    protected $description = 'Ask Kalveri AI for a safe metadata review episode match suggestion.';

    public function handle(KalveriAIMediaMatcherService $matcher): int
    {
        $episode = Episode::with('user')->find($this->argument('episode_id'));

        if (! $episode || ! $episode->user) {
            $this->error('AI review match failed: episode was not found.');

            return self::FAILURE;
        }

        $result = $matcher->matchMetadataReviewEpisode($episode->user, $episode);
        $suggestion = $result['suggestion'] ?? [];

        $this->line('episode_id: '.$episode->id);
        $this->line('show_id: '.$episode->show_id);
        $this->line('season_number: '.$episode->season_number);
        $this->line('episode_number: '.$episode->episode_number);
        $this->line('status: '.($suggestion['status'] ?? 'unknown'));
        $this->line('tmdb_season: '.($suggestion['tmdbSeason'] ?? 'none'));
        $this->line('tmdb_episode: '.($suggestion['tmdbEpisode'] ?? 'none'));
        $this->line('confidence: '.($suggestion['confidence'] ?? 0));
        $this->line('requires_confirmation: '.(($suggestion['requiresConfirmation'] ?? true) ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
