<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\PlaybackProgress;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class BackupMediaHubUserCommand extends Command
{
    protected $signature = 'mediahub:backup-user {user_id}';

    protected $description = 'Create a private provider-safe MediaHub backup for one user.';

    public function handle(AuditLogService $auditLogs): int
    {
        $user = User::find($this->argument('user_id'));

        if (! $user) {
            $this->error('Backup failed: user was not found.');

            return self::FAILURE;
        }

        $directory = storage_path('app/private/mediahub-backups');
        File::ensureDirectoryExists($directory, 0700);

        $payload = $this->payloadFor($user);
        $summary = $this->summary($payload['tables']);
        $path = $directory.'/user-'.$user->id.'-'.now()->format('Ymd-His').'.json';

        try {
            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $this->error('Backup failed: payload could not be encoded.');

            return self::FAILURE;
        }

        $auditLogs->record('mediahub.backup.created', $user, null, $user, [
            ...$summary,
            'path_hash' => hash('sha256', $path),
        ]);

        $this->line('MediaHub backup created');
        $this->printSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(User $user): array
    {
        return [
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'user_id' => $user->id,
            'tables' => [
                'movies' => Movie::forUser($user)->orderBy('id')->get()->map(fn (Movie $movie): array => [
                    'old_id' => $movie->id,
                    'external_source' => $movie->external_source,
                    'external_id' => $movie->external_id,
                    'title' => $movie->title,
                    'poster_url' => $movie->poster_url,
                    'runtime' => $movie->runtime,
                    'is_to_watch' => $movie->is_to_watch,
                    'created_at' => $movie->created_at?->toIso8601String(),
                    'updated_at' => $movie->updated_at?->toIso8601String(),
                ])->all(),
                'shows' => Show::forUser($user)->orderBy('id')->get()->map(fn (Show $show): array => [
                    'old_id' => $show->id,
                    'external_source' => $show->external_source,
                    'external_id' => $show->external_id,
                    'title' => $show->title,
                    'poster_url' => $show->poster_url,
                    'fanart_url' => $show->fanart_url,
                    'followed' => $show->followed,
                    'seen_episodes' => $show->seen_episodes,
                    'aired_episodes' => $show->aired_episodes,
                    'runtime' => $show->runtime,
                    'latest_seen_at' => $show->latest_seen_at?->toIso8601String(),
                    'created_at' => $show->created_at?->toIso8601String(),
                    'updated_at' => $show->updated_at?->toIso8601String(),
                ])->all(),
                'episodes' => Episode::forUser($user)->orderBy('id')->get()->map(fn (Episode $episode): array => [
                    'old_id' => $episode->id,
                    'show_old_id' => $episode->show_id,
                    'external_source' => $episode->external_source,
                    'external_id' => $episode->external_id,
                    'season_number' => $episode->season_number,
                    'episode_number' => $episode->episode_number,
                    'title' => $episode->title,
                    'runtime' => $episode->runtime,
                    'air_date' => $episode->air_date?->toDateString(),
                    'created_at' => $episode->created_at?->toIso8601String(),
                    'updated_at' => $episode->updated_at?->toIso8601String(),
                ])->all(),
                'movie_watches' => MovieWatch::forUser($user)->orderBy('id')->get()->map(fn (MovieWatch $watch): array => [
                    'movie_old_id' => $watch->movie_id,
                    'watched_at' => $watch->watched_at?->toIso8601String(),
                    'runtime' => $watch->runtime,
                    'watch_count' => $watch->watch_count,
                    'source' => $watch->source,
                ])->all(),
                'episode_watches' => EpisodeWatch::forUser($user)->orderBy('id')->get()->map(fn (EpisodeWatch $watch): array => [
                    'show_old_id' => $watch->show_id,
                    'episode_old_id' => $watch->episode_id,
                    'watched_at' => $watch->watched_at?->toIso8601String(),
                    'runtime' => $watch->runtime,
                    'source' => $watch->source,
                ])->all(),
                'ratings' => Rating::forUser($user)->orderBy('id')->get()->map(fn (Rating $rating): array => [
                    'media_type' => $rating->media_type,
                    'media_old_id' => $rating->media_id,
                    'rating' => $rating->rating,
                    'created_at' => $rating->created_at?->toIso8601String(),
                    'updated_at' => $rating->updated_at?->toIso8601String(),
                ])->all(),
                'notes' => Note::forUser($user)->orderBy('id')->get()->map(fn (Note $note): array => [
                    'media_type' => $note->media_type,
                    'media_old_id' => $note->media_id,
                    'body' => $note->body,
                    'created_at' => $note->created_at?->toIso8601String(),
                    'updated_at' => $note->updated_at?->toIso8601String(),
                ])->all(),
                'media_links' => MediaLink::forUser($user)->orderBy('id')->get()->map(fn (MediaLink $link): array => [
                    'playback_source_item_id' => $link->playback_source_item_id,
                    'movie_old_id' => $link->movie_id,
                    'show_old_id' => $link->show_id,
                    'episode_old_id' => $link->episode_id,
                    'linked_at' => $link->linked_at?->toIso8601String(),
                ])->all(),
                'playback_progress' => PlaybackProgress::forUser($user)->orderBy('id')->get()->map(fn (PlaybackProgress $progress): array => [
                    'playback_source_item_id' => $progress->playback_source_item_id,
                    'movie_old_id' => $progress->movie_id,
                    'episode_old_id' => $progress->episode_id,
                    'position_seconds' => $progress->position_seconds,
                    'duration_seconds' => $progress->duration_seconds,
                    'completed' => $progress->completed,
                ])->all(),
            ],
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array<string, int>
     */
    private function summary(array $tables): array
    {
        return collect($tables)
            ->map(fn (array $rows): int => count($rows))
            ->all();
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function printSummary(array $summary): void
    {
        foreach ($summary as $table => $count) {
            $this->line($table.': '.$count);
        }
    }
}
