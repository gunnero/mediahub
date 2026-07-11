<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Alert;
use App\Models\EpisodeWatch;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
