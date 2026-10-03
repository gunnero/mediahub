<?php

namespace Tests\Feature;

use App\Models\MediaList;
use App\Models\MediaPreference;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\User;
use App\Services\LibraryImportService;
use App\Services\UserExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LibraryImportCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_preserves_annotations_and_repeated_import_deduplicates_history(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Archive', 'tmdb_id' => 14]);
        Note::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'body' => 'Keep this memory']);
        $payload = app(UserExportService::class)->payload($user);
        $payload['movie_watches'][] = ['id' => 1, 'movie_id' => $movie->id, 'watched_at' => '2024-01-01T20:00:00Z', 'runtime' => 90, 'watch_count' => 1];
        $file = UploadedFile::fake()->createWithContent('archive.json', json_encode($payload));
        $batch = $this->actingAs($user)->postJson('/api/v1/imports/preview', ['file' => $file])->assertCreated()->assertJsonPath('batch.status', 'preview')->json('batch.id');
        $this->assertDatabaseCount('movie_watches', 0);
        $this->postJson("/api/v1/imports/$batch/apply", ['confirm' => true])->assertOk()->assertJsonPath('batch.status', 'completed');
        $this->assertDatabaseCount('notes', 1);
        $this->assertDatabaseCount('movie_watches', 1);
        $this->postJson("/api/v1/imports/$batch/apply", ['confirm' => true])->assertOk();
        $this->assertDatabaseCount('movie_watches', 1);
        $this->postJson("/api/v1/imports/$batch/recover", ['confirm' => true])->assertNoContent();
        $this->assertDatabaseCount('movie_watches', 0);
        $this->assertDatabaseCount('notes', 1);
    }

    public function test_stale_preview_and_changed_library_cannot_be_overwritten(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Archive']);
        $service = app(LibraryImportService::class);
        $batch = $service->preview($user, app(UserExportService::class)->payload($user));
        $movie->update(['title' => 'Renamed']);
        $this->actingAs($user)->postJson("/api/v1/imports/{$batch->id}/apply", ['confirm' => true])->assertStatus(409);
        $other = User::factory()->create();
        $this->actingAs($other)->postJson("/api/v1/imports/{$batch->id}/apply", ['confirm' => true])->assertNotFound();
    }

    public function test_new_media_recovery_is_verified_and_private_payload_never_leaks(): void
    {
        $user = User::factory()->create();
        $data = app(UserExportService::class)->payload($user);
        $data['movies'][] = ['id' => 123, 'title' => 'Brand new', 'runtime' => 90];
        $service = app(LibraryImportService::class);
        $batch = $service->preview($user, $data);
        $service->apply($user, $batch);
        $this->actingAs($user)->getJson('/api/v1/imports')->assertOk()->assertJsonMissingPath('batches.0.payload')->assertJsonMissingPath('batches.0.backup');
        $this->postJson("/api/v1/imports/{$batch->id}/recover", ['confirm' => true])->assertNoContent();
        $this->assertDatabaseCount('movies', 0);
    }

    public function test_preview_reports_duplicate_watches_without_changing_the_library(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Repeat']);
        MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => '2024-01-01', 'watch_count' => 1]);
        $data = app(UserExportService::class)->payload($user);
        $batch = app(LibraryImportService::class)->preview($user, $data);
        $this->assertSame(1, $batch->summary['duplicateWatches']);
        $this->assertDatabaseCount('movie_watches', 1);
        $this->actingAs($user)->postJson("/api/v1/imports/{$batch->id}/apply", ['confirm' => true])->assertJsonPath('batch.summary.duplicateWatchesSkipped', 1);
        $this->assertDatabaseCount('movie_watches', 1);
    }

    public function test_export_round_trip_preserves_smart_rules_and_private_library_preferences(): void
    {
        $source = User::factory()->create();
        $target = User::factory()->create();
        $movie = Movie::create(['user_id' => $source->id, 'title' => 'Round trip']);
        MediaPreference::create(['user_id' => $source->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'pinned' => true, 'tags' => ['weekend']]);
        MediaList::create(['user_id' => $source->id, 'name' => 'Weekend', 'rules' => ['type' => 'movie', 'tag' => 'weekend'], 'cover_style' => 'blue', 'visibility' => 'public', 'share_token_hash' => hash('sha256', 'private-token')]);
        $data = app(UserExportService::class)->payload($source);
        $this->assertArrayNotHasKey('share_token_hash', $data['lists'][0]);
        $file = UploadedFile::fake()->createWithContent('archive.json', json_encode($data));
        $batch = $this->actingAs($target)->postJson('/api/v1/imports/preview', ['file' => $file])->assertCreated()->json('batch.id');
        $this->postJson("/api/v1/imports/$batch/apply", ['confirm' => true])->assertOk();
        $this->getJson('/api/v1/lists')->assertJsonPath('lists.0.rules.tag', 'weekend')->assertJsonPath('lists.0.itemsCount', 1)->assertJsonPath('lists.0.visibility', 'private')->assertJsonPath('lists.0.shared', false);
        $this->postJson("/api/v1/imports/$batch/recover", ['confirm' => true])->assertNoContent();
        $this->assertDatabaseMissing('media_preferences', ['user_id' => $target->id]);
    }
}
