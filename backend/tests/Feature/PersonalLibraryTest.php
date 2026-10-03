<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\MediaEvent;
use App\Models\MediaPreference;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Show;
use App\Models\User;
use App\Services\MediaEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_watch_edit_delete_and_undo_preserve_other_watches(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Archive']);
        $first = MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => '2024-01-01', 'watch_count' => 1, 'runtime' => 90, 'source' => 'manual']);
        $second = MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => '2024-02-01', 'watch_count' => 1, 'runtime' => 90, 'source' => 'manual']);
        $this->actingAs($user)->patchJson("/api/v1/history/movie/{$first->id}", ['watched_at' => '2024-01-10'])->assertOk();
        $this->assertSame('2024-01-10', $first->fresh()->watched_at->toDateString());
        $undo = $this->deleteJson("/api/v1/history/movie/{$first->id}")->assertOk()->json('undoId');
        $this->assertModelExists($second);
        $this->postJson("/api/v1/history/undo/$undo")->assertNoContent();
        $this->assertSame('2024-01-10', $first->fresh()->watched_at->toDateString());
        $this->postJson("/api/v1/history/undo/$undo")->assertStatus(409);
        $this->getJson("/api/v1/history/movie/{$movie->id}")->assertJsonPath('total', 2);
    }

    public function test_undo_cannot_overwrite_a_newer_edit_and_other_users_cannot_edit(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Archive']);
        $watch = MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => '2024-01-01']);
        $undo = $this->actingAs($user)->patchJson("/api/v1/history/movie/{$watch->id}", ['watched_at' => '2024-02-01'])->assertOk()->json('undoId');
        $this->patchJson("/api/v1/history/movie/{$watch->id}", ['watched_at' => '2024-03-01'])->assertOk();
        $this->postJson("/api/v1/history/undo/$undo")->assertStatus(409);
        $this->patchJson("/api/v1/history/movie/{$watch->id}", ['watched_at' => now()->addDay()->toIso8601String()])->assertUnprocessable();
        $this->actingAs($other)->deleteJson("/api/v1/history/movie/{$watch->id}")->assertNotFound();
        $this->postJson("/api/v1/history/undo/$undo")->assertNotFound();
    }

    public function test_bulk_is_atomic_and_tags_can_filter_library(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = Movie::create(['user_id' => $user->id, 'title' => 'Mine']);
        $theirs = Movie::create(['user_id' => $other->id, 'title' => 'Theirs']);
        $this->actingAs($user)->postJson('/api/v1/library/bulk', ['type' => 'movie', 'ids' => [$mine->id, $theirs->id], 'action' => 'watchlist'])->assertNotFound();
        $this->assertFalse($mine->fresh()->is_to_watch);
        $this->postJson('/api/v1/library/bulk', ['type' => 'movie', 'ids' => [$mine->id], 'action' => 'tag', 'tag' => 'weekend'])->assertOk();
        $this->getJson('/api/v1/library/movies?tag=weekend')->assertJsonCount(1, 'items');
        $this->getJson('/api/v1/library/movies?tag=unknown')->assertJsonCount(0, 'items');
    }

    public function test_queue_respects_pins_pause_air_dates_and_time_budget(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['user_id' => $user->id, 'title' => 'One', 'followed' => true]);
        $other = Show::create(['user_id' => $user->id, 'title' => 'Two', 'followed' => true]);
        foreach ([$show, $other] as $item) {
            foreach ([1, 2, 3] as $number) {
                Episode::create(['user_id' => $user->id, 'show_id' => $item->id, 'season_number' => 1, 'episode_number' => $number, 'runtime' => 25, 'air_date' => $number === 3 ? now()->addMonth() : now()->subMonth()]);
            }
        }
        MediaPreference::create(['user_id' => $user->id, 'media_type' => 'show', 'media_id' => $other->id, 'pinned' => true]);
        $this->actingAs($user)->getJson('/api/v1/queue?minutes=30')->assertOk()->assertJsonPath('items.0.showId', $other->id)->assertJsonPath('items.0.remaining', 2)->assertJsonPath('items.0.catchUpMinutes', 50);
        $this->patchJson("/api/v1/preferences/show/{$other->id}", ['status' => 'paused'])->assertOk();
        $this->getJson('/api/v1/queue')->assertJsonCount(1, 'items');
        $this->getJson('/api/v1/queue?minutes=20')->assertJsonCount(0, 'items');
    }

    public function test_edit_and_undo_keep_legacy_diary_entries_in_sync(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Old diary']);
        $watch = MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => '2024-01-01 20:00:00']);
        $event = MediaEvent::create(['user_id' => $user->id, 'subject_type' => Movie::class, 'subject_id' => $movie->id, 'event_type' => 'movie.watched', 'occurred_at' => $watch->watched_at, 'source' => 'manual', 'metadata' => ['timeline' => true]]);
        $this->actingAs($user)->patchJson("/api/v1/history/movie/{$watch->id}", ['watched_at' => '2024-01-02T22:00:00+02:00'])->assertOk();
        $this->assertSame('2024-01-02 20:00:00', $event->fresh()->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame($watch->id, $event->fresh()->metadata['watch_id']);
        $undo = $this->deleteJson("/api/v1/history/movie/{$watch->id}")->assertOk()->json('undoId');
        $this->assertEmpty(app(MediaEventService::class)->timeline($user));
        $this->assertEmpty(app(MediaEventService::class)->dashboardTimeline($user)['recent']);
        $this->postJson("/api/v1/history/undo/$undo")->assertNoContent();
        $this->assertCount(1, app(MediaEventService::class)->timeline($user));
        $this->assertFalse($event->fresh()->metadata['watch_removed']);
    }
}
