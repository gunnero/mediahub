<?php

namespace Tests\Feature;

use App\Models\ExperienceSetting;
use App\Models\Friendship;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Rating;
use App\Models\ReleaseReminder;
use App\Models\User;
use App\Services\ReleasePlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2ExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_smart_collections_change_with_history_and_public_shares_are_revocable(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'A short thriller', 'runtime' => 90, 'genres' => ['Thriller'], 'poster_url' => 'https://private.example.test/secret']);
        $list = $this->actingAs($user)->postJson('/api/v1/lists', ['name' => 'Tonight', 'rules' => ['type' => 'movie', 'genre' => 'Thriller', 'max_runtime' => 120, 'unwatched' => true]])->assertCreated()->assertJsonPath('list.itemsCount', 1)->json('list.id');
        $url = $this->postJson("/api/v1/lists/$list/share")->assertOk()->json('url');
        $token = basename($url);
        auth()->logout();
        $this->getJson('/api/v1/collections/'.$token)->assertOk()->assertJsonPath('items.0.title', 'A short thriller')->assertJsonMissingPath('items.0.mediaId')->assertJsonMissingPath('rules')->assertJsonPath('items.0.poster', '');
        $this->actingAs($user)->postJson("/api/v1/library/movies/{$movie->id}/watch")->assertCreated();
        $this->getJson("/api/v1/lists/$list")->assertJsonPath('list.itemsCount', 0);
        $this->deleteJson("/api/v1/lists/$list/share")->assertNoContent();
        $this->getJson('/api/v1/collections/'.$token)->assertNotFound();
    }

    public function test_curated_discovery_sends_filters_and_keeps_dismissals_after_reload(): void
    {
        config(['tmdb.enabled' => true, 'tmdb.api_key' => 'test', 'tmdb.cache_store' => 'array']);
        Http::fake(['api.themoviedb.org/*' => Http::response(['results' => [['id' => 99, 'title' => 'Suggestion', 'genre_ids' => [18], 'vote_average' => 8]], 'total_pages' => 1, 'total_results' => 1])]);
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Favorite drama', 'genres' => ['Drama']]);
        Rating::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'rating' => 9]);
        $this->actingAs($user)->getJson('/api/v1/discover/curated?type=movie&category=recommended&max_runtime=120&year=2024&language=en&min_rating=7')->assertOk()->assertJsonPath('items.0.reason', 'Matches your interest in Drama');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/discover/movie') && $request['with_genres'] === 18 && $request['primary_release_year'] === '2024');
        $this->postJson('/api/v1/discover/dismiss', ['type' => 'movie', 'tmdb_id' => 99])->assertOk();
        $this->getJson('/api/v1/discover/browse?type=movie')->assertJsonCount(0, 'items');
        $this->deleteJson('/api/v1/discover/dismiss')->assertNoContent();
        $this->getJson('/api/v1/discover/browse?type=movie')->assertJsonCount(1, 'items');
    }

    public function test_reminders_observe_quiet_hours_snooze_and_calendar_revocation(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(23, 0));
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => "Title\nBEGIN:VEVENT", 'release_date' => now()->addDay(), 'is_to_watch' => true]);
        ExperienceSetting::create(['user_id' => $user->id, 'quiet_start' => '22:00', 'quiet_end' => '08:00', 'weekly_digest' => true]);
        $reminder = ReleaseReminder::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'remind_at' => now()->subMinute()]);
        app(ReleasePlanningService::class)->deliver($user);
        $this->assertDatabaseCount('alerts', 0);
        $this->travel(10)->hours();
        app(ReleasePlanningService::class)->deliver($user);
        $this->assertDatabaseCount('alerts', 2);
        app(ReleasePlanningService::class)->deliver($user);
        $this->assertDatabaseCount('alerts', 2);
        $url = $this->actingAs($user)->postJson('/api/v1/calendar/subscription')->assertOk()->json('url');
        $response = $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
        $this->assertStringContainsString('SUMMARY:Title\\nBEGIN:VEVENT', $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), "\r\nBEGIN:VEVENT\r\n"));
        $this->deleteJson('/api/v1/calendar/subscription')->assertNoContent();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        $this->patchJson("/api/v1/reminders/{$reminder->id}", ['remind_at' => now()->addDay()->toIso8601String()])->assertOk()->assertJsonPath('reminder.delivered_at', null);
        $this->postJson('/api/v1/push/subscription', ['endpoint' => 'https://127.0.0.1/private', 'keys' => ['p256dh' => 'fake', 'auth' => 'fake']])->assertUnprocessable();
    }

    public function test_movie_night_requires_accepted_invitation_and_friendship_and_votes_are_idempotent(): void
    {
        $owner = User::factory()->create(['profile_slug' => 'owner']);
        $friend = User::factory()->create(['profile_slug' => 'friend']);
        $outsider = User::factory()->create();
        $relationship = Friendship::create(['requester_user_id' => $owner->id, 'addressee_user_id' => $friend->id, 'pair_key' => $owner->id.':'.$friend->id, 'status' => 'accepted']);
        $movie = Movie::create(['user_id' => $owner->id, 'title' => 'Shared title']);
        $room = $this->actingAs($owner)->postJson('/api/v1/movie-nights', ['name' => 'Friday'])->assertCreated()->json('id');
        $this->postJson("/api/v1/movie-nights/$room/invite", ['slug' => 'friend'])->assertNoContent();
        $this->postJson("/api/v1/movie-nights/$room/titles", ['type' => 'movie', 'media_id' => $movie->id])->assertNoContent();
        $title = DB::table('movie_night_titles')->first()->id;
        $this->actingAs($friend)->postJson("/api/v1/movie-night-titles/$title/vote", ['vote' => true])->assertNotFound();
        $this->postJson("/api/v1/movie-nights/$room/join")->assertNoContent();
        $this->postJson("/api/v1/movie-night-titles/$title/vote", ['vote' => true])->assertNoContent();
        $this->postJson("/api/v1/movie-night-titles/$title/vote", ['vote' => true])->assertNoContent();
        $this->getJson('/api/v1/movie-nights')->assertJsonPath('rooms.0.titles.0.votes', 1);
        $this->deleteJson("/api/v1/movie-night-titles/$title")->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/v1/movie-night-titles/$title/vote", ['vote' => true])->assertNotFound();
        $relationship->update(['status' => 'blocked', 'blocked_by_user_id' => $owner->id]);
        $this->actingAs($friend)->getJson('/api/v1/movie-nights')->assertJsonCount(0, 'rooms');
        $this->postJson("/api/v1/movie-night-titles/$title/vote", ['vote' => true])->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/v1/movie-night-titles/$title")->assertNoContent();
        $this->assertDatabaseCount('movie_night_votes', 0);
    }

    public function test_friend_activity_requires_explicit_opt_in_and_only_shares_new_watches(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['requester_user_id' => $user->id, 'addressee_user_id' => $friend->id, 'pair_key' => $user->id.':'.$friend->id, 'status' => 'accepted']);
        $movie = Movie::create(['user_id' => $friend->id, 'title' => 'Private history']);
        MovieWatch::create(['user_id' => $friend->id, 'movie_id' => $movie->id, 'watched_at' => now()->subYear()]);
        $this->actingAs($user)->getJson('/api/v1/movie-nights')->assertJsonCount(0, 'activity');
        $this->actingAs($friend)->patchJson('/api/v1/experience-settings', ['share_activity' => true])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/movie-nights')->assertJsonCount(0, 'activity');
        MovieWatch::create(['user_id' => $friend->id, 'movie_id' => $movie->id, 'watched_at' => now()->addSecond()]);
        $this->getJson('/api/v1/movie-nights')->assertJsonCount(1, 'activity')->assertJsonMissingPath('activity.0.user_id');
    }

    public function test_offline_retries_never_duplicate_watches_and_cross_account_targets_fail(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Offline']);
        $entry = ['id' => (string) Str::uuid(), 'type' => 'movie', 'media_id' => $movie->id, 'watched_at' => '2024-01-01T20:00:00Z'];
        $this->actingAs($user)->postJson('/api/v1/offline/watches', ['entries' => [$entry]])->assertOk();
        $this->postJson('/api/v1/offline/watches', ['entries' => [$entry]])->assertOk();
        $this->assertDatabaseCount('movie_watches', 1);
        $this->postJson('/api/v1/offline/watches', ['entries' => [[...$entry, 'watched_at' => '2024-02-01']]])->assertStatus(409);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/offline/watches', ['entries' => [$entry]])->assertNotFound();
    }

    public function test_statistics_ranges_use_local_days_and_leave_out_other_accounts(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Skopje']);
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Midnight watch']);
        MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => '2024-01-01 23:30:00', 'runtime' => 90, 'watch_count' => 1]);
        $this->actingAs($user)->getJson('/api/v1/stats?from=2024-01-02&to=2024-01-02')->assertOk()->assertJsonPath('summary.moviesWatched', 1)->assertJsonPath('dailyActivity.0.period', '2024-01-02');
        $this->getJson('/api/v1/stats?from=2024-01-01&to=2024-01-01')->assertJsonPath('summary.moviesWatched', 0);
    }

    public function test_rating_distribution_respects_the_selected_period(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Rated']);
        Rating::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'rating' => 8])->forceFill(['created_at' => '2024-01-01', 'updated_at' => '2024-01-01'])->save();
        $this->actingAs($user)->getJson('/api/v1/stats?from=2024-01-01&to=2024-12-31')->assertJsonCount(1, 'ratings');
        $this->getJson('/api/v1/stats?from=2025-01-01&to=2025-12-31')->assertJsonCount(0, 'ratings')->assertJsonCount(0, 'ratingTrends');
    }

    public function test_deleted_title_does_not_block_other_reminders(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Available']);
        ReleaseReminder::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => 999, 'remind_at' => now()->subHour()]);
        ReleaseReminder::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'remind_at' => now()->subHour()]);
        app(ReleasePlanningService::class)->deliver($user);
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('release_reminders', 1);
    }

    public function test_push_setup_preserves_keys_and_restricts_file_permissions(): void
    {
        $storage = $this->app->storagePath();
        $temporary = sys_get_temp_dir().'/mediahub-push-'.Str::uuid();
        $this->app->useStoragePath($temporary);
        config(['webpush.public_key' => null, 'webpush.private_key' => null]);
        try {
            $this->artisan('mediahub:configure-push')->assertSuccessful();
            $path = $temporary.'/app/private/webpush/keys.json';
            $keys = file_get_contents($path);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertNotEmpty(json_decode($keys, true)['privateKey']);
            $this->artisan('mediahub:configure-push')->assertSuccessful();
            $this->assertSame($keys, file_get_contents($path));
        } finally {
            $this->app->useStoragePath($storage);
            File::deleteDirectory($temporary);
        }
    }
}
