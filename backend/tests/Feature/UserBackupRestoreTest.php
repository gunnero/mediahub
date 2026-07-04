<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UserBackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/private/mediahub-backups'));
    }

    public function test_backup_file_is_created_without_stream_urls_or_provider_secrets(): void
    {
        $user = $this->userWithLibrary();

        $this->artisan("mediahub:backup-user {$user->id}")
            ->expectsOutputToContain('MediaHub backup created')
            ->expectsOutputToContain('movies: 1')
            ->expectsOutputToContain('ratings: 1')
            ->assertExitCode(0);

        $backupFile = $this->latestBackupFor($user);
        $this->assertFileExists($backupFile);

        $json = File::get($backupFile);

        $this->assertStringContainsString('"movies"', $json);
        $this->assertStringContainsString('"ratings"', $json);
        $this->assertStringContainsString('"notes"', $json);
        $this->assertStringNotContainsString('stream_url', $json);
        $this->assertStringNotContainsString('https://private.example.test/stream/movie', $json);
        $this->assertStringNotContainsString('https://private.example.test/playlist', $json);
        $this->assertStringNotContainsString('secret-token', $json);
        $this->assertStringNotContainsString('api-key', $json);
    }

    public function test_restore_rebuilds_user_library_and_private_annotations(): void
    {
        $user = $this->userWithLibrary();
        $this->artisan("mediahub:backup-user {$user->id}")->assertExitCode(0);
        $backupFile = $this->latestBackupFor($user);

        MovieWatch::forUser($user)->delete();
        EpisodeWatch::forUser($user)->delete();
        $user->ratings()->delete();
        $user->notes()->delete();
        $user->episodes()->delete();
        $user->shows()->delete();
        $user->movies()->delete();

        $this->artisan("mediahub:restore-user {$user->id} {$backupFile}")
            ->expectsOutputToContain('MediaHub restore completed')
            ->expectsOutputToContain('movies: 1')
            ->expectsOutputToContain('notes: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('movies', ['user_id' => $user->id, 'title' => 'Backup Movie']);
        $this->assertDatabaseHas('shows', ['user_id' => $user->id, 'title' => 'Backup Show']);
        $this->assertDatabaseHas('episode_watches', ['user_id' => $user->id, 'source' => 'manual']);
        $this->assertDatabaseHas('ratings', ['user_id' => $user->id, 'media_type' => 'movie', 'rating' => 8]);
        $this->assertDatabaseHas('notes', ['user_id' => $user->id, 'media_type' => 'movie', 'body' => 'Private backup note.']);
    }

    public function test_restore_rejects_files_outside_private_backup_directory(): void
    {
        $user = $this->member();
        $outsideFile = storage_path('outside-mediahub-backup.json');
        File::put($outsideFile, '{}');

        $this->artisan("mediahub:restore-user {$user->id} {$outsideFile}")
            ->expectsOutputToContain('Backup file must be inside private MediaHub backup storage.')
            ->assertExitCode(1);

        File::delete($outsideFile);
    }

    public function test_restore_into_another_user_keeps_user_isolation(): void
    {
        $sourceUser = $this->userWithLibrary('source@example.test');
        $targetUser = $this->member('target@example.test');

        $this->artisan("mediahub:backup-user {$sourceUser->id}")->assertExitCode(0);
        $backupFile = $this->latestBackupFor($sourceUser);

        $this->artisan("mediahub:restore-user {$targetUser->id} {$backupFile}")
            ->assertExitCode(0);

        $this->assertDatabaseHas('movies', ['user_id' => $targetUser->id, 'title' => 'Backup Movie']);
        $this->assertDatabaseHas('ratings', ['user_id' => $targetUser->id, 'media_type' => 'movie', 'rating' => 8]);
        $this->assertDatabaseMissing('ratings', ['user_id' => $targetUser->id, 'media_id' => Movie::forUser($sourceUser)->firstOrFail()->id]);
    }

    private function userWithLibrary(string $email = 'member@example.test'): User
    {
        $user = $this->member($email);
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Backup Movie',
            'runtime' => 100,
        ]);
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Backup Show',
            'followed' => true,
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'title' => 'Backup Episode',
            'runtime' => 45,
        ]);

        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now(),
            'runtime' => 100,
            'source' => 'manual',
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episode->id,
            'watched_at' => now(),
            'runtime' => 45,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/rating", ['rating' => 8])
            ->assertOk();
        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/notes", ['body' => 'Private backup note.'])
            ->assertCreated();

        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'Private Plex',
            'provider_type' => 'plex',
            'status' => 'active',
            'settings' => [
                'provider_secret' => 'secret-token',
                'playlist_url' => 'https://private.example.test/playlist',
                'api_key' => 'api-key',
            ],
        ]);
        PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => 'movie',
            'kind' => 'movie',
            'title' => 'Provider Movie',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/movie',
            'stream_url_hash' => hash('sha256', 'https://private.example.test/stream/movie'),
        ]);

        return $user;
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
    }

    private function latestBackupFor(User $user): string
    {
        $files = glob(storage_path("app/private/mediahub-backups/user-{$user->id}-*.json"));

        $this->assertNotEmpty($files);

        rsort($files);

        return $files[0];
    }
}
