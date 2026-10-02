<?php

namespace App\Console\Commands;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Alert;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaEvent;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Show;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\AuditLogService;
use App\Services\MediaEventService;
use App\Services\TvTimeImportSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportTvTimeUserCommand extends Command
{
    protected $signature = 'tvtime:import-user {user_id} {path_to_sqlite_or_json}
        {--dry-run : Validate and preview counts without changing data}
        {--replace : Back up and replace an existing unannotated library}';

    protected $description = 'Import a private TV Time dashboard SQLite or JSON snapshot for one user.';

    public function handle(AnalyticsService $analytics, AuditLogService $auditLogs, MediaEventService $mediaEvents, TvTimeImportSource $reader): int
    {
        $user = User::find($this->argument('user_id'));

        if (! $user) {
            $this->error('Import failed: user was not found.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path_to_sqlite_or_json');

        if (! is_file($path)) {
            $this->error('Import failed: source file was not found.');

            return self::FAILURE;
        }

        $realPath = realpath($path);

        if (! $realPath || ! $this->isAllowedImportPath($realPath)) {
            $this->error('Import failed: source file is outside the allowed private import paths.');

            return self::FAILURE;
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

        if (! in_array($extension, ['sqlite', 'sqlite3', 'db', 'json'], true)) {
            $this->error('Import failed: source file must be SQLite or JSON.');

            return self::FAILURE;
        }

        try {
            $source = $reader->read($realPath, $extension);
        } catch (Throwable) {
            $this->error('Import failed: invalid, empty, or unsupported source snapshot. No data was changed.');

            return self::FAILURE;
        }

        $counts = $this->existingCounts($user);
        $this->table(['Dataset', 'Source rows', 'Existing rows'], [
            ['Shows', count($source['shows']), $counts['shows']],
            ['Movies', count($source['movies']), $counts['movies']],
            ['Episode watches', count($source['episode_watches']), $counts['episode_watches']],
            ['Movie watches', count(array_filter($source['movies'], fn (array $movie): bool => $source['format'] === 'sqlite' && ! $this->boolValue($movie['is_to_watch'] ?? false))), $counts['movie_watches']],
            ['Existing episodes', 'derived from episode watches', $counts['episodes']],
            ['Alerts', count($source['alerts']), $counts['alerts']],
        ]);
        if ($source['format'] === 'json') {
            $this->warn('Legacy dashboard JSON contains preview titles and alerts only, not full watch history.');
        }
        $blocker = $this->replacementBlocker($user, $source, $counts);
        if ($this->option('dry-run')) {
            if ($blocker) {
                $this->warn($blocker);
            }
            if (array_sum($counts) > 0) {
                $this->warn('Existing library detected. Replacement requires --replace and a private backup.');
            }
            $this->info('Dry run complete. No data was changed.');

            return self::SUCCESS;
        }

        try {
            $summary = DB::transaction(function () use ($source, $user): array {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                $counts = $this->existingCounts($user);
                if ($blocker = $this->replacementBlocker($user, $source, $counts)) {
                    throw new RuntimeException($blocker);
                }
                if (array_sum($counts) > 0) {
                    if (! $this->option('replace')) {
                        throw new RuntimeException('Import refused: existing library requires --replace. Review --dry-run first.');
                    }
                    $this->backupLibrary($user);
                    $this->clearExistingLibrary($user);
                }

                return $source['format'] === 'json'
                    ? $this->importDashboardJson($user, $source)
                    : $this->importSqlite($user, $source);
            });
        } catch (RuntimeException $exception) {
            // Only deliberate guard failures have messages suitable for the console.
            if (! str_starts_with($exception->getMessage(), 'Import refused:')) {
                report($exception);
            }
            $this->error(str_starts_with($exception->getMessage(), 'Import refused:')
                ? $exception->getMessage()
                : 'Import failed: source data could not be imported. No data was changed.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Import failed: source data could not be imported.');

            return self::FAILURE;
        }

        $analytics->record('tvtime.import.completed', $user, $summary);
        $auditLogs->record('tvtime.import.completed', $user, null, $user, $summary);
        $mediaEvents->record($user, MediaEventType::ShowImported, null, [
            'count' => $summary['shows_imported'],
            'episodes_imported' => $summary['episodes_imported'],
        ], MediaEventSource::Import);
        $mediaEvents->record($user, MediaEventType::EpisodeImported, null, [
            'count' => $summary['episodes_imported'],
            'watches_imported' => $summary['watches_imported'],
        ], MediaEventSource::Import);
        $mediaEvents->record($user, MediaEventType::MovieImported, null, [
            'count' => $summary['movies_imported'],
            'watches_imported' => $summary['watches_imported'],
        ], MediaEventSource::Import);

        $this->line('shows imported: '.$summary['shows_imported']);
        $this->line('episodes imported: '.$summary['episodes_imported']);
        $this->line('movies imported: '.$summary['movies_imported']);
        $this->line('watches imported: '.$summary['watches_imported']);
        $this->line('alerts imported: '.$summary['alerts_imported']);

        return self::SUCCESS;
    }

    private function existingCounts(User $user): array
    {
        $counts = [];
        foreach (['shows', 'movies', 'episodes', 'episode_watches', 'movie_watches', 'alerts'] as $table) {
            $counts[$table] = DB::table($table)->where('user_id', $user->id)->count();
        }

        return $counts;
    }

    private function replacementBlocker(User $user, array $source, array $counts): ?string
    {
        foreach (['notes', 'ratings', 'media_list_items', 'media_links', 'playback_sessions', 'playback_progress'] as $table) {
            if (DB::table($table)->where('user_id', $user->id)->exists()) {
                return 'Import refused: personal annotations or media links must be preserved. Import into an empty account instead.';
            }
        }
        if ($user->favorite_movie_ids || $user->favorite_show_ids
            || MediaEvent::forUser($user)->whereNotNull('subject_id')->exists()) {
            return 'Import refused: favorites or diary links must be preserved. Import into an empty account instead.';
        }
        if ($source['format'] === 'json' && ($counts['episode_watches'] + $counts['movie_watches']) > 0) {
            return 'Import refused: a dashboard preview cannot replace watch history. Use the complete SQLite snapshot.';
        }

        return null;
    }

    private function backupLibrary(User $user): void
    {
        $backup = ['schema' => 'mediahub-import-backup-v1', 'user_id' => $user->id, 'created_at' => now()->toIso8601String(), 'tables' => []];
        foreach (array_keys($this->existingCounts($user)) as $table) {
            $backup['tables'][$table] = DB::table($table)->where('user_id', $user->id)->orderBy('id')->get()->all();
        }
        $path = 'import-backups/user-'.$user->id.'/'.Str::uuid().'.json';
        if (! Storage::disk('local')->put($path, json_encode($backup, JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
            throw new RuntimeException('Import refused: private backup could not be saved.');
        }
    }

    private function clearExistingLibrary(User $user): void
    {
        Alert::forUser($user)->delete();
        EpisodeWatch::forUser($user)->delete();
        MovieWatch::forUser($user)->delete();
        Episode::forUser($user)->delete();
        Show::forUser($user)->delete();
        Movie::forUser($user)->delete();
    }

    /**
     * @return array{shows_imported:int,episodes_imported:int,movies_imported:int,watches_imported:int,alerts_imported:int}
     */
    private function importSqlite(User $user, array $source): array
    {
        $showMap = [];

        foreach ($source['shows'] as $row) {
            $show = Show::create([
                'user_id' => $user->id,
                'external_source' => 'tvtime',
                'external_id' => $this->stringOrNull($row['show_key'] ?? null),
                'title' => $this->stringOrDefault($row['title'] ?? null, 'Untitled show'),
                'poster_url' => $this->stringOrNull($row['poster_url'] ?? null),
                'fanart_url' => $this->stringOrNull($row['fanart_url'] ?? null),
                'followed' => $this->boolValue($row['followed'] ?? false),
                'seen_episodes' => $this->intValue($row['seen_episodes'] ?? 0),
                'aired_episodes' => $this->intValue($row['aired_episodes'] ?? 0),
                'runtime' => $this->intValue($row['runtime'] ?? 0),
                'latest_seen_at' => $this->dateTimeOrNull($row['latest_seen_at'] ?? null),
            ]);

            if ($show->external_id) {
                $showMap[$show->external_id] = $show;
            }
        }

        $episodeMap = [];
        $episodeWatchesImported = 0;

        foreach ($source['episode_watches'] as $row) {
            $showKey = $this->stringOrNull($row['show_key'] ?? null);
            $show = $showKey ? ($showMap[$showKey] ?? null) : null;

            if (! $show && $showKey) {
                $show = Show::create([
                    'user_id' => $user->id,
                    'external_source' => 'tvtime',
                    'external_id' => $showKey,
                    'title' => $this->stringOrDefault($row['show_title'] ?? null, 'Untitled show'),
                    'followed' => false,
                ]);
                $showMap[$showKey] = $show;
            }

            $externalEpisodeId = $this->stringOrNull($row['episode_id'] ?? null)
                ?? 'legacy-watch-'.$this->stringOrDefault($row['id'] ?? null, (string) ($episodeWatchesImported + 1));

            $episode = $episodeMap[$externalEpisodeId] ??= Episode::firstOrCreate([
                'user_id' => $user->id,
                'external_source' => 'tvtime',
                'external_id' => $externalEpisodeId,
            ], [
                'show_id' => $show?->id,
                'season_number' => $this->nullableInt($row['season_number'] ?? null),
                'episode_number' => $this->nullableInt($row['episode_number'] ?? null),
                'runtime' => $this->intValue($row['runtime'] ?? $show?->runtime ?? 0),
            ]);

            EpisodeWatch::create([
                'user_id' => $user->id,
                'show_id' => $show?->id,
                'episode_id' => $episode->id,
                'watched_at' => $this->dateTimeOrNull($row['watched_at'] ?? null),
                'runtime' => $this->intValue($row['runtime'] ?? $episode->runtime ?? 0),
                'source' => 'tvtime-import',
            ]);
            $episodeWatchesImported++;
        }

        $movieWatchesImported = 0;

        foreach ($source['movies'] as $row) {
            $movie = Movie::create([
                'user_id' => $user->id,
                'external_source' => 'tvtime',
                'external_id' => $this->stringOrNull($row['uuid'] ?? null),
                'title' => $this->stringOrDefault($row['title'] ?? null, 'Untitled movie'),
                'runtime' => $this->intValue($row['runtime'] ?? 0),
                'is_to_watch' => $this->boolValue($row['is_to_watch'] ?? false),
            ]);

            if (! $movie->is_to_watch) {
                MovieWatch::create([
                    'user_id' => $user->id,
                    'movie_id' => $movie->id,
                    'watched_at' => $this->dateTimeOrNull($row['watched_at'] ?? null),
                    'runtime' => $this->intValue($row['runtime'] ?? 0),
                    'watch_count' => max(1, $this->intValue($row['watch_count'] ?? 1)),
                    'source' => 'tvtime-import',
                ]);
                $movieWatchesImported++;
            }
        }

        foreach ($source['alerts'] as $row) {
            Alert::create([
                'user_id' => $user->id,
                'category' => $this->stringOrDefault($row['category'] ?? null, 'site'),
                'title' => $this->stringOrDefault($row['title'] ?? null, 'TV Time alert'),
                'subtitle' => $this->stringOrDefault($row['subtitle'] ?? null, ''),
                'due_text' => $this->stringOrDefault($row['due_text'] ?? null, 'Available now'),
                'payload' => null,
                'unread' => $this->boolValue($row['unread'] ?? true),
                'read_at' => $this->boolValue($row['unread'] ?? true) ? null : now(),
            ]);
        }

        return [
            'shows_imported' => Show::forUser($user)->count(),
            'episodes_imported' => Episode::forUser($user)->count(),
            'movies_imported' => Movie::forUser($user)->count(),
            'watches_imported' => $episodeWatchesImported + $movieWatchesImported,
            'alerts_imported' => Alert::forUser($user)->count(),
        ];
    }

    /**
     * @return array{shows_imported:int,episodes_imported:int,movies_imported:int,watches_imported:int,alerts_imported:int}
     */
    private function importDashboardJson(User $user, array $source): array
    {
        foreach ($source['shows'] as $item) {
            Show::create([
                'user_id' => $user->id,
                'external_source' => 'dashboard-json',
                'external_id' => $this->stringOrNull($item['id'] ?? null),
                'title' => $this->stringOrDefault($item['title'] ?? null, 'Untitled show'),
                'poster_url' => $this->stringOrNull($item['poster'] ?? null),
                'fanart_url' => $this->stringOrNull($item['backdrop'] ?? null),
                'followed' => true,
            ]);
        }

        foreach ($source['movies'] as $item) {
            Movie::create([
                'user_id' => $user->id,
                'external_source' => 'dashboard-json',
                'external_id' => $this->stringOrNull($item['id'] ?? null),
                'title' => $this->stringOrDefault($item['title'] ?? null, 'Untitled movie'),
                'poster_url' => $this->stringOrNull($item['poster'] ?? null),
                'is_to_watch' => true,
            ]);
        }

        foreach ($source['alerts'] as $item) {
            Alert::create([
                'user_id' => $user->id,
                'category' => $this->stringOrDefault($item['category'] ?? null, 'site'),
                'title' => $this->stringOrDefault($item['title'] ?? null, 'TV Time alert'),
                'subtitle' => $this->stringOrDefault($item['subtitle'] ?? null, ''),
                'due_text' => $this->stringOrDefault($item['dueText'] ?? null, 'Available now'),
                'unread' => $this->boolValue($item['unread'] ?? true),
            ]);
        }

        return [
            'shows_imported' => Show::forUser($user)->count(),
            'episodes_imported' => Episode::forUser($user)->count(),
            'movies_imported' => Movie::forUser($user)->count(),
            'watches_imported' => EpisodeWatch::forUser($user)->count() + MovieWatch::forUser($user)->count(),
            'alerts_imported' => Alert::forUser($user)->count(),
        ];
    }

    private function isAllowedImportPath(string $path): bool
    {
        foreach ($this->allowedImportDirectories() as $directory) {
            if ($path === $directory || str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedImportDirectories(): array
    {
        $directories = [
            base_path('../var/private'),
            base_path('../public/data'),
            storage_path('app/private'),
            storage_path('app/imports'),
        ];

        return array_values(array_filter(array_map(
            static fn (string $directory): string|false => realpath($directory),
            $directories,
        )));
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
