<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Alert;
use App\Models\EpisodeWatch;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDO;
use Tests\TestCase;

class TvTimeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_command_imports_private_sqlite_for_one_user_and_keeps_payload_compatible(): void
    {
        $user = User::factory()->create([
            'name' => 'Archive Owner',
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
        $fixture = $this->writeSqliteFixture('tiny-tvtime.sqlite');

        $this->artisan('tvtime:import-user', [
            'user_id' => $user->id,
            'path_to_sqlite_or_json' => $fixture,
        ])
            ->expectsOutput('shows imported: 2')
            ->expectsOutput('episodes imported: 2')
            ->expectsOutput('movies imported: 2')
            ->expectsOutput('watches imported: 3')
            ->expectsOutput('alerts imported: 2')
            ->assertExitCode(0);

        $this->assertSame(2, Show::forUser($user)->count());
        $this->assertSame(2, EpisodeWatch::forUser($user)->count());
        $this->assertSame(2, Movie::forUser($user)->count());
        $this->assertSame(1, MovieWatch::forUser($user)->count());
        $this->assertSame(2, Alert::forUser($user)->count());

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('profile.name', 'Archive Owner')
            ->assertJsonPath('stats.episodesWatched', 2)
            ->assertJsonPath('stats.moviesWatched', 1)
            ->assertJsonPath('stats.showsFollowed', 2)
            ->assertJsonPath('stats.alertsUnread', 2)
            ->assertJsonPath('recentlyWatched.0.title', 'Frequency')
            ->assertJsonPath('followedNewEpisodes.0.title', 'Manifest')
            ->assertJsonPath('moviesToCheckOut.0.title', 'Arrival')
            ->assertJsonPath('topShows.0.title', 'Manifest')
            ->assertJsonCount(3, 'alerts')
            ->assertJsonCount(7, 'activity');

        $otherUser = User::factory()->create([
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($otherUser)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.episodesWatched', 0)
            ->assertJsonPath('stats.moviesWatched', 0)
            ->assertJsonPath('stats.alertsUnread', 0)
            ->assertJsonCount(0, 'alerts');
    }

    public function test_import_command_rejects_missing_users_missing_files_and_unapproved_paths(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
        $fixture = $this->writeSqliteFixture('reject-source.sqlite');
        $outside = tempnam(sys_get_temp_dir(), 'tvtime-outside-');

        $this->artisan('tvtime:import-user', [
            'user_id' => 999999,
            'path_to_sqlite_or_json' => $fixture,
        ])->assertExitCode(1);

        $this->artisan('tvtime:import-user', [
            'user_id' => $user->id,
            'path_to_sqlite_or_json' => storage_path('app/private/import-fixtures/missing.sqlite'),
        ])->assertExitCode(1);

        $this->artisan('tvtime:import-user', [
            'user_id' => $user->id,
            'path_to_sqlite_or_json' => $outside,
        ])->assertExitCode(1);

        @unlink((string) $outside);
    }

    public function test_invalid_json_never_replaces_an_existing_library(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Keep this movie']);
        $note = Note::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'body' => 'Keep this note']);
        foreach (['{}', '[]', 'null', '{', '{"moviesToCheckOut":[]}', '{"followedNewEpisodes":[],"moviesToCheckOut":[{"id":1,"title":[]}],"alerts":[]}', '{"followedNewEpisodes":[],"moviesToCheckOut":[],"alerts":[]}'] as $index => $json) {
            $path = $this->writeJsonFixture('invalid-'.$index.'.json', $json);
            $this->artisan('tvtime:import-user', ['user_id' => $user->id, 'path_to_sqlite_or_json' => $path, '--replace' => true])->assertExitCode(1);
            $this->assertDatabaseHas('movies', ['id' => $movie->id, 'title' => 'Keep this movie']);
            $this->assertDatabaseHas('notes', ['id' => $note->id, 'media_id' => $movie->id]);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('import-backups'));
        $this->assertDatabaseCount('media_events', 0);
    }

    public function test_valid_snapshot_dry_run_does_not_write_or_back_up(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Existing movie']);
        $this->artisan('tvtime:import-user', ['user_id' => $user->id, 'path_to_sqlite_or_json' => $this->writeSqliteFixture('dry-run.sqlite'), '--dry-run' => true, '--replace' => true])->assertExitCode(0);
        $this->assertDatabaseCount('movies', 1);
        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertDatabaseCount('shows', 0);
        $this->assertDatabaseCount('media_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('import-backups'));
    }

    public function test_existing_library_requires_explicit_replacement_and_receives_a_private_backup(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Existing movie']);
        $otherMovie = Movie::create(['user_id' => $other->id, 'title' => 'Other account movie']);
        $arguments = ['user_id' => $user->id, 'path_to_sqlite_or_json' => $this->writeSqliteFixture('replace.sqlite')];
        $this->artisan('tvtime:import-user', $arguments)->assertExitCode(1);
        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertSame([], Storage::disk('local')->allFiles('import-backups'));
        $this->artisan('tvtime:import-user', [...$arguments, '--replace' => true])->assertExitCode(0);
        $this->assertDatabaseMissing('movies', ['id' => $movie->id]);
        $this->assertDatabaseHas('movies', ['id' => $otherMovie->id]);
        $files = Storage::disk('local')->allFiles('import-backups');
        $this->assertCount(1, $files);
        $this->assertSame('private', Storage::disk('local')->getVisibility($files[0]));
        $backup = json_decode(Storage::disk('local')->get($files[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('mediahub-import-backup-v1', $backup['schema']);
        $this->assertCount(1, $backup['tables']['movies']);
        $this->assertSame($movie->id, $backup['tables']['movies'][0]['id']);
        $this->assertSame($user->id, $backup['tables']['movies'][0]['user_id']);
        $this->assertArrayNotHasKey('users', $backup['tables']);
    }

    public function test_replacement_refuses_to_orphan_notes_or_favorites(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Annotated movie']);
        $note = Note::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'body' => 'Personal note']);
        $arguments = ['user_id' => $user->id, 'path_to_sqlite_or_json' => $this->writeSqliteFixture('annotations.sqlite'), '--replace' => true];
        $this->artisan('tvtime:import-user', $arguments)->assertExitCode(1);
        $this->assertDatabaseHas('notes', ['id' => $note->id, 'media_id' => $movie->id]);
        $note->delete();
        $user->update(['favorite_movie_ids' => [$movie->id]]);
        $this->artisan('tvtime:import-user', $arguments)->assertExitCode(1);
        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertSame([], Storage::disk('local')->allFiles('import-backups'));
    }

    public function test_failed_backup_preserves_the_existing_library(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Keep without backup']);
        $this->artisan('tvtime:import-user', ['user_id' => $user->id, 'path_to_sqlite_or_json' => $this->writeSqliteFixture('backup-failure.sqlite'), '--replace' => true])->assertExitCode(1);
        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertDatabaseCount('shows', 0);
        $this->assertDatabaseCount('media_events', 0);
    }

    public function test_invalid_sqlite_rows_are_rejected_before_replacement(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Keep on invalid date']);
        $path = $this->writeSqliteFixture('invalid-date.sqlite');
        (new PDO('sqlite:'.$path))->exec("UPDATE movies SET watched_at = 'not-a-date'");
        $this->artisan('tvtime:import-user', ['user_id' => $user->id, 'path_to_sqlite_or_json' => $path, '--replace' => true])->assertExitCode(1);
        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertSame([], Storage::disk('local')->allFiles('import-backups'));
    }

    public function test_legacy_json_imports_preview_titles_but_cannot_replace_watch_history(): void
    {
        $user = User::factory()->create();
        $path = $this->writeJsonFixture('valid-dashboard.json', json_encode(['followedNewEpisodes' => [], 'moviesToCheckOut' => [['id' => 'preview-movie', 'title' => 'Preview movie']], 'alerts' => []]));
        $arguments = ['user_id' => $user->id, 'path_to_sqlite_or_json' => $path];
        $this->artisan('tvtime:import-user', $arguments)->assertExitCode(0);
        $movie = Movie::forUser($user)->firstOrFail();
        $this->assertSame('Preview movie', $movie->title);
        MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => now(), 'runtime' => 90, 'source' => 'manual']);
        $this->artisan('tvtime:import-user', [...$arguments, '--replace' => true])->assertExitCode(1);
        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertDatabaseCount('movie_watches', 1);
    }

    private function writeJsonFixture(string $name, string $json): string
    {
        $directory = storage_path('app/private/import-fixtures');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/'.$name;
        file_put_contents($path, $json);

        return $path;
    }

    private function writeSqliteFixture(string $name): string
    {
        $directory = storage_path('app/private/import-fixtures');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.$name;
        @unlink($path);

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
            create table shows (
                show_key text primary key,
                tvtime_id text,
                title text not null,
                poster_url text,
                fanart_url text,
                followed integer not null default 0,
                seen_episodes integer not null default 0,
                aired_episodes integer not null default 0,
                runtime integer not null default 0,
                latest_seen_at text
            );

            create table episode_watches (
                id integer primary key autoincrement,
                episode_id text,
                show_key text,
                show_title text not null,
                season_number integer,
                episode_number integer,
                watched_at text,
                runtime integer not null default 0
            );

            create table movies (
                uuid text primary key,
                title text not null,
                watched_at text,
                runtime integer not null default 0,
                watch_count integer not null default 1,
                is_to_watch integer not null default 0
            );

            create table alerts (
                id text primary key,
                category text not null,
                title text not null,
                subtitle text not null,
                due_text text not null,
                unread integer not null default 1
            );
            SQL
        );

        DB::connection()->getPdo();

        $pdo->exec(
            <<<'SQL'
            insert into shows values
                ('manifest', 'tv-1', 'Manifest', '/poster-manifest.jpg', '/fanart-manifest.jpg', 1, 2, 3, 42, '2026-07-03 20:00:00'),
                ('dark', 'tv-2', 'Dark', '/poster-dark.jpg', '/fanart-dark.jpg', 1, 1, 1, 50, '2026-07-01 21:00:00');

            insert into episode_watches (episode_id, show_key, show_title, season_number, episode_number, watched_at, runtime) values
                ('ep-manifest-1', 'manifest', 'Manifest', 4, 1, '2026-07-03 20:00:00', 42),
                ('ep-dark-1', 'dark', 'Dark', 1, 1, '2026-07-01 21:00:00', 50);

            insert into movies values
                ('movie-frequency', 'Frequency', '2026-07-04 22:00:00', 118, 1, 0),
                ('movie-arrival', 'Arrival', null, 116, 1, 1);

            insert into alerts values
                ('alert-episode', 'new-episodes', 'Manifest has a new episode', 'Season 4 continues', 'Today', 1),
                ('alert-movie', 'movies', 'Arrival is on your watchlist', 'Saved for later', 'Later', 0);
            SQL
        );

        return $path;
    }
}
