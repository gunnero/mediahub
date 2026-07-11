<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\PlaybackSource;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebV1ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_is_user_scoped_and_includes_upcoming_movies_and_episodes(): void
    {
        $user = $this->member();
        $other = $this->member('other@example.test');
        $show = Show::create(['user_id' => $user->id, 'title' => 'Own Show', 'followed' => true, 'seen_episodes' => 1, 'aired_episodes' => 2]);
        Episode::create(['user_id' => $user->id, 'show_id' => $show->id, 'season_number' => 2, 'episode_number' => 3, 'title' => 'Return', 'air_date' => now()->addDays(2)]);
        $otherShow = Show::create(['user_id' => $other->id, 'title' => 'Other Show', 'followed' => true]);
        Episode::create(['user_id' => $other->id, 'show_id' => $otherShow->id, 'season_number' => 1, 'episode_number' => 1, 'air_date' => now()->addDays(2)]);
        Movie::create(['user_id' => $user->id, 'title' => 'Own Movie', 'is_to_watch' => true, 'release_date' => now()->addDays(4)]);

        $response = $this->actingAs($user)->getJson('/api/v1/calendar?date_from='.now()->toDateString().'&date_to='.now()->addMonth()->toDateString());
        $response->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.title', 'Own Show');
        $this->assertStringNotContainsString('Other Show', $response->getContent());
    }

    public function test_statistics_match_user_watch_rows(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Movie', 'runtime' => 120, 'genres' => [['name' => 'Drama']]]);
        MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => now(), 'runtime' => 120, 'watch_count' => 2, 'source' => 'manual']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'Show', 'seen_episodes' => 1, 'aired_episodes' => 1, 'genres' => [['name' => 'Drama']]]);
        $episode = Episode::create(['user_id' => $user->id, 'show_id' => $show->id, 'season_number' => 1, 'episode_number' => 1, 'runtime' => 60]);
        EpisodeWatch::create(['user_id' => $user->id, 'show_id' => $show->id, 'episode_id' => $episode->id, 'watched_at' => now(), 'runtime' => 60, 'source' => 'manual']);
        Rating::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'rating' => 9]);

        $this->actingAs($user)->getJson('/api/v1/stats')
            ->assertOk()
            ->assertJsonPath('summary.moviesWatched', 1)
            ->assertJsonPath('summary.episodesWatched', 1)
            ->assertJsonPath('summary.showsCompleted', 1)
            ->assertJsonPath('summary.totalWatchMinutes', 300)
            ->assertJsonPath('summary.rewatchCount', 1)
            ->assertJsonPath('ratings.0.rating', 9);
    }

    public function test_private_lists_support_crud_reorder_and_user_isolation(): void
    {
        $user = $this->member();
        $other = $this->member('other@example.test');
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Movie']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'Show']);

        $listId = $this->actingAs($user)->postJson('/api/v1/lists', ['name' => 'Favorites'])
            ->assertCreated()->assertJsonPath('list.visibility', 'private')->json('list.id');
        $first = $this->actingAs($user)->postJson("/api/v1/lists/{$listId}/items", ['media_type' => 'movie', 'media_id' => $movie->id])->assertCreated()->json('item.id');
        $second = $this->actingAs($user)->postJson("/api/v1/lists/{$listId}/items", ['media_type' => 'show', 'media_id' => $show->id])->assertCreated()->json('item.id');
        $otherMovie = Movie::create(['user_id' => $other->id, 'title' => 'Other User Movie']);
        MediaListItem::create(['user_id' => $other->id, 'media_list_id' => $listId, 'media_type' => 'movie', 'media_id' => $otherMovie->id, 'position' => 99]);
        $this->actingAs($user)->patchJson("/api/v1/lists/{$listId}/reorder", ['item_ids' => [$second, $first]])->assertOk()->assertJsonPath('list.items.0.id', $second);
        $this->actingAs($user)->getJson("/api/v1/lists/{$listId}")
            ->assertOk()
            ->assertJsonPath('list.itemsCount', 2)
            ->assertJsonMissing(['title' => 'Other User Movie']);
        $this->actingAs($other)->getJson("/api/v1/lists/{$listId}")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/lists/{$listId}")->assertNotFound();
    }

    public function test_alert_generation_and_preferences_are_private_and_safe(): void
    {
        $user = $this->member();
        $show = Show::create(['user_id' => $user->id, 'title' => 'Upcoming Show', 'followed' => true]);
        Episode::create(['user_id' => $user->id, 'show_id' => $show->id, 'season_number' => 1, 'episode_number' => 2, 'title' => 'Next', 'air_date' => now()->addDays(2)]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 3,
            'title' => 'Needs review',
            'metadata_review_status' => 'pending',
            'metadata_failure_count' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonFragment(['category' => 'upcoming'])
            ->assertJsonFragment(['subtitle' => '1 episode need manual review']);
        $this->assertStringNotContainsString('2 episodes need manual review', $response->getContent());
        $this->actingAs($user)->patchJson('/api/v1/notification-preferences', ['new_episodes' => false, 'email_enabled' => false])
            ->assertOk()->assertJsonPath('preferences.newEpisodes', false)->assertJsonPath('preferences.emailEnabled', false);
    }

    public function test_exports_include_owned_tracking_data_and_exclude_provider_secrets(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Exported Movie']);
        Movie::create(['user_id' => $user->id, 'title' => '=HYPERLINK("https://invalid.test","Open")']);
        Note::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'body' => 'Private note']);
        PlaybackSource::create(['user_id' => $user->id, 'name' => 'Private Provider', 'provider_type' => 'xtream', 'status' => 'active', 'settings' => ['base_url' => 'https://provider.invalid', 'username' => 'private-user', 'password' => 'private-password']]);
        $other = $this->member('export-other@example.test');
        $otherMovie = Movie::create(['user_id' => $other->id, 'title' => 'Other Export Movie']);
        $list = MediaList::create(['user_id' => $user->id, 'name' => 'Private List', 'visibility' => 'private']);
        MediaListItem::create(['user_id' => $other->id, 'media_list_id' => $list->id, 'media_type' => 'movie', 'media_id' => $otherMovie->id]);

        $response = $this->actingAs($user)->get('/api/v1/exports/json')->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Exported Movie', $content);
        $this->assertStringContainsString('Private note', $content);
        foreach (['provider.invalid', 'private-user', 'private-password', 'stream_url', 'provider_url'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
        $this->assertSame([], json_decode($content, true, flags: JSON_THROW_ON_ERROR)['lists'][0]['items']);

        $csv = $this->actingAs($user)->get('/api/v1/exports/csv/movies')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_settings_summary_is_user_scoped_and_provider_free(): void
    {
        $user = $this->member();
        Movie::create(['user_id' => $user->id, 'title' => 'Movie', 'metadata_refreshed_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('metadata.provider', 'TMDB')
            ->assertJsonPath('metadata.movies.enriched', 1)
            ->assertJsonPath('privacy.providerDataInWeb', false);
        foreach (['password', 'stream_url', 'provider_url'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent());
        }
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'role' => UserRole::Member, 'status' => UserStatus::Active]);
    }
}
