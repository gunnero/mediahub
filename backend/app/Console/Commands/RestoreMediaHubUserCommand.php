<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\PlaybackProgress;
use App\Models\PlaybackSourceItem;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

class RestoreMediaHubUserCommand extends Command
{
    protected $signature = 'mediahub:restore-user {user_id} {backup_file}';

    protected $description = 'Restore a private provider-safe MediaHub backup into one user account.';

    public function handle(AuditLogService $auditLogs): int
    {
        $user = User::find($this->argument('user_id'));

        if (! $user) {
            $this->error('Restore failed: user was not found.');

            return self::FAILURE;
        }

        $path = $this->validatedBackupPath((string) $this->argument('backup_file'));

        if (! $path) {
            return self::FAILURE;
        }

        try {
            $payload = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            $summary = DB::transaction(fn (): array => $this->restorePayload($user, $payload));
        } catch (JsonException) {
            $this->error('Restore failed: backup JSON is invalid.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Restore failed: backup could not be restored.');

            return self::FAILURE;
        }

        $auditLogs->record('mediahub.backup.restored', $user, null, $user, [
            ...$summary,
            'path_hash' => hash('sha256', $path),
        ]);

        $this->line('MediaHub restore completed');
        $this->printSummary($summary);

        return self::SUCCESS;
    }

    private function validatedBackupPath(string $path): ?string
    {
        $directory = storage_path('app/private/mediahub-backups');
        File::ensureDirectoryExists($directory, 0700);

        if (! is_file($path)) {
            $this->error('Restore failed: backup file was not found.');

            return null;
        }

        $realPath = realpath($path);
        $realDirectory = realpath($directory);

        if (! $realPath || ! $realDirectory || ! str_starts_with($realPath, $realDirectory.DIRECTORY_SEPARATOR)) {
            $this->error('Backup file must be inside private MediaHub backup storage.');

            return null;
        }

        return $realPath;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function restorePayload(User $user, array $payload): array
    {
        $tables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];

        $this->clearRestorableData($user);

        $movieMap = [];
        $showMap = [];
        $episodeMap = [];

        foreach ($this->rows($tables, 'movies') as $row) {
            $movie = Movie::create([
                'user_id' => $user->id,
                'external_source' => $this->stringOrNull($row['external_source'] ?? null),
                'external_id' => $this->stringOrNull($row['external_id'] ?? null),
                'title' => $this->stringOrDefault($row['title'] ?? null, 'Untitled movie'),
                'poster_url' => $this->stringOrNull($row['poster_url'] ?? null),
                'runtime' => $this->intValue($row['runtime'] ?? 0),
                'is_to_watch' => $this->boolValue($row['is_to_watch'] ?? false),
            ]);
            $movieMap[(int) ($row['old_id'] ?? 0)] = $movie->id;
        }

        foreach ($this->rows($tables, 'shows') as $row) {
            $show = Show::create([
                'user_id' => $user->id,
                'external_source' => $this->stringOrNull($row['external_source'] ?? null),
                'external_id' => $this->stringOrNull($row['external_id'] ?? null),
                'title' => $this->stringOrDefault($row['title'] ?? null, 'Untitled show'),
                'poster_url' => $this->stringOrNull($row['poster_url'] ?? null),
                'fanart_url' => $this->stringOrNull($row['fanart_url'] ?? null),
                'followed' => $this->boolValue($row['followed'] ?? false),
                'seen_episodes' => $this->intValue($row['seen_episodes'] ?? 0),
                'aired_episodes' => $this->intValue($row['aired_episodes'] ?? 0),
                'runtime' => $this->intValue($row['runtime'] ?? 0),
                'latest_seen_at' => $this->dateTimeOrNull($row['latest_seen_at'] ?? null),
            ]);
            $showMap[(int) ($row['old_id'] ?? 0)] = $show->id;
        }

        foreach ($this->rows($tables, 'episodes') as $row) {
            $episode = Episode::create([
                'user_id' => $user->id,
                'show_id' => $showMap[(int) ($row['show_old_id'] ?? 0)] ?? null,
                'external_source' => $this->stringOrNull($row['external_source'] ?? null),
                'external_id' => $this->stringOrNull($row['external_id'] ?? null),
                'season_number' => $this->nullableInt($row['season_number'] ?? null),
                'episode_number' => $this->nullableInt($row['episode_number'] ?? null),
                'title' => $this->stringOrNull($row['title'] ?? null),
                'runtime' => $this->intValue($row['runtime'] ?? 0),
                'air_date' => $this->stringOrNull($row['air_date'] ?? null),
            ]);
            $episodeMap[(int) ($row['old_id'] ?? 0)] = $episode->id;
        }

        foreach ($this->rows($tables, 'movie_watches') as $row) {
            MovieWatch::create([
                'user_id' => $user->id,
                'movie_id' => $movieMap[(int) ($row['movie_old_id'] ?? 0)] ?? null,
                'watched_at' => $this->dateTimeOrNull($row['watched_at'] ?? null),
                'runtime' => $this->intValue($row['runtime'] ?? 0),
                'watch_count' => max(1, $this->intValue($row['watch_count'] ?? 1)),
                'source' => $this->stringOrNull($row['source'] ?? null),
            ]);
        }

        foreach ($this->rows($tables, 'episode_watches') as $row) {
            EpisodeWatch::create([
                'user_id' => $user->id,
                'show_id' => $showMap[(int) ($row['show_old_id'] ?? 0)] ?? null,
                'episode_id' => $episodeMap[(int) ($row['episode_old_id'] ?? 0)] ?? null,
                'watched_at' => $this->dateTimeOrNull($row['watched_at'] ?? null),
                'runtime' => $this->intValue($row['runtime'] ?? 0),
                'source' => $this->stringOrNull($row['source'] ?? null),
            ]);
        }

        foreach ($this->rows($tables, 'ratings') as $row) {
            $mediaType = $this->stringOrNull($row['media_type'] ?? null);
            $mediaId = $this->mappedMediaId($mediaType, (int) ($row['media_old_id'] ?? 0), $movieMap, $showMap, $episodeMap);

            if ($mediaType && $mediaId) {
                Rating::create([
                    'user_id' => $user->id,
                    'media_type' => $mediaType,
                    'media_id' => $mediaId,
                    'rating' => $this->intValue($row['rating'] ?? 0),
                ]);
            }
        }

        foreach ($this->rows($tables, 'notes') as $row) {
            $mediaType = $this->stringOrNull($row['media_type'] ?? null);
            $mediaId = $this->mappedMediaId($mediaType, (int) ($row['media_old_id'] ?? 0), $movieMap, $showMap, $episodeMap);

            if ($mediaType && $mediaId) {
                Note::create([
                    'user_id' => $user->id,
                    'media_type' => $mediaType,
                    'media_id' => $mediaId,
                    'body' => $this->stringOrDefault($row['body'] ?? null, ''),
                ]);
            }
        }

        foreach ($this->rows($tables, 'media_links') as $row) {
            $sourceItem = PlaybackSourceItem::forUser($user)->find((int) ($row['playback_source_item_id'] ?? 0));

            if (! $sourceItem) {
                continue;
            }

            MediaLink::create([
                'user_id' => $user->id,
                'playback_source_item_id' => $sourceItem->id,
                'movie_id' => $movieMap[(int) ($row['movie_old_id'] ?? 0)] ?? null,
                'show_id' => $showMap[(int) ($row['show_old_id'] ?? 0)] ?? null,
                'episode_id' => $episodeMap[(int) ($row['episode_old_id'] ?? 0)] ?? null,
                'linked_at' => $this->dateTimeOrNull($row['linked_at'] ?? null) ?? now(),
            ]);
        }

        foreach ($this->rows($tables, 'playback_progress') as $row) {
            $sourceItem = PlaybackSourceItem::forUser($user)->find((int) ($row['playback_source_item_id'] ?? 0));

            if (! $sourceItem) {
                continue;
            }

            PlaybackProgress::create([
                'user_id' => $user->id,
                'playback_source_item_id' => $sourceItem->id,
                'movie_id' => $movieMap[(int) ($row['movie_old_id'] ?? 0)] ?? null,
                'episode_id' => $episodeMap[(int) ($row['episode_old_id'] ?? 0)] ?? null,
                'position_seconds' => $this->intValue($row['position_seconds'] ?? 0),
                'duration_seconds' => $this->nullableInt($row['duration_seconds'] ?? null),
                'completed' => $this->boolValue($row['completed'] ?? false),
            ]);
        }

        return [
            'movies' => Movie::forUser($user)->count(),
            'shows' => Show::forUser($user)->count(),
            'episodes' => Episode::forUser($user)->count(),
            'movie_watches' => MovieWatch::forUser($user)->count(),
            'episode_watches' => EpisodeWatch::forUser($user)->count(),
            'ratings' => Rating::forUser($user)->count(),
            'notes' => Note::forUser($user)->count(),
            'media_links' => MediaLink::forUser($user)->count(),
            'playback_progress' => PlaybackProgress::forUser($user)->count(),
        ];
    }

    private function clearRestorableData(User $user): void
    {
        MediaLink::forUser($user)->delete();
        PlaybackProgress::forUser($user)->delete();
        EpisodeWatch::forUser($user)->delete();
        MovieWatch::forUser($user)->delete();
        Rating::forUser($user)->delete();
        Note::forUser($user)->delete();
        Episode::forUser($user)->delete();
        Show::forUser($user)->delete();
        Movie::forUser($user)->delete();
    }

    /**
     * @param  array<string, mixed>  $tables
     * @return list<array<string, mixed>>
     */
    private function rows(array $tables, string $table): array
    {
        $rows = $tables[$table] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param  array<int, int>  $movieMap
     * @param  array<int, int>  $showMap
     * @param  array<int, int>  $episodeMap
     */
    private function mappedMediaId(?string $mediaType, int $oldId, array $movieMap, array $showMap, array $episodeMap): ?int
    {
        return match ($mediaType) {
            'movie' => $movieMap[$oldId] ?? null,
            'show' => $showMap[$oldId] ?? null,
            'episode' => $episodeMap[$oldId] ?? null,
            default => null,
        };
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

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return $this->stringOrNull($value) ?? $default;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    private function intValue(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return max(0, (int) $value);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->intValue($value);
    }

    private function dateTimeOrNull(mixed $value): ?CarbonImmutable
    {
        $value = $this->stringOrNull($value);

        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
