<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\PlaybackSession;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerProviderAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_always_available_and_player_empty_without_provider(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('player.enabled', false)
            ->assertJsonPath('player.emptyState', 'Attach your own source to enable playback and automatic tracking.')
            ->assertJsonCount(0, 'player.sourceItems');
    }

    public function test_user_can_list_only_their_own_playback_sources(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $ownSource = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'My Plex',
            'provider_type' => 'plex',
            'status' => 'active',
        ]);
        PlaybackSource::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Source',
            'provider_type' => 'jellyfin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/player/sources')
            ->assertOk()
            ->assertJsonCount(1, 'sources')
            ->assertJsonPath('sources.0.id', $ownSource->id)
            ->assertJsonMissing(['name' => 'Other Source']);
    }

    public function test_dashboard_player_is_enabled_with_provider_without_exposing_stream_urls(): void
    {
        $user = $this->member();
        $item = $this->sourceItemFor($user);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('player.enabled', true)
            ->assertJsonPath('player.sourceItems.0.id', $item->id)
            ->assertJsonMissingPath('player.sourceItems.0.stream_url')
            ->assertJsonMissingPath('player.sourceItems.0.streamUrl')
            ->assertJsonMissingPath('player.sourceItems.0.playbackUrl');
    }

    public function test_user_cannot_play_another_users_source_item(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherItem = $this->sourceItemFor($otherUser);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$otherItem->id}/play")
            ->assertNotFound();
    }

    public function test_user_cannot_link_another_users_source_item_or_canonical_item(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $ownItem = $this->sourceItemFor($user);
        $otherItem = $this->sourceItemFor($otherUser);
        $ownMovie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Own Movie',
        ]);
        $otherMovie = Movie::create([
            'user_id' => $otherUser->id,
            'title' => 'Other Movie',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$otherItem->id}/link", [
                'movie_id' => $ownMovie->id,
                'confirm' => true,
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$ownItem->id}/link", [
                'movie_id' => $otherMovie->id,
                'confirm' => true,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('media_links', [
            'playback_source_item_id' => $otherItem->id,
            'movie_id' => $ownMovie->id,
        ]);
        $this->assertDatabaseMissing('media_links', [
            'playback_source_item_id' => $ownItem->id,
            'movie_id' => $otherMovie->id,
        ]);
    }

    public function test_user_cannot_update_another_users_playback_session(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherItem = $this->sourceItemFor($otherUser);
        $session = PlaybackSession::create([
            'user_id' => $otherUser->id,
            'playback_source_id' => $otherItem->playback_source_id,
            'playback_source_item_id' => $otherItem->id,
            'status' => 'playing',
            'started_at' => now(),
            'last_position_seconds' => 12,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/v1/player/sessions/{$session->id}", [
                'position_seconds' => 120,
                'duration_seconds' => 600,
            ])
            ->assertNotFound();

        $this->assertSame(12, $session->fresh()->last_position_seconds);
    }

    public function test_player_rejects_source_item_with_another_users_provider_source(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherSource = PlaybackSource::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Provider',
            'provider_type' => 'plex',
            'status' => 'active',
        ]);
        $tamperedItem = PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $otherSource->id,
            'external_id' => 'tampered-1',
            'kind' => 'movie',
            'title' => 'Tampered Item',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/tampered',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$tamperedItem->id}/play")
            ->assertNotFound();
    }

    public function test_dashboard_excludes_source_items_with_mismatched_provider_source_owner(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'Own Provider',
            'provider_type' => 'plex',
            'status' => 'active',
        ]);
        $otherSource = PlaybackSource::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Provider',
            'provider_type' => 'plex',
            'status' => 'active',
        ]);
        PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $otherSource->id,
            'external_id' => 'tampered-1',
            'kind' => 'movie',
            'title' => 'Tampered Item',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/tampered',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('player.enabled', true)
            ->assertJsonMissing(['title' => 'Tampered Item']);
    }

    public function test_user_cannot_update_session_with_mismatched_provider_source_owner(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherSource = PlaybackSource::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Provider',
            'provider_type' => 'plex',
            'status' => 'active',
        ]);
        $tamperedItem = PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $otherSource->id,
            'external_id' => 'tampered-session-1',
            'kind' => 'movie',
            'title' => 'Tampered Session Item',
            'status' => 'available',
        ]);
        $session = PlaybackSession::create([
            'user_id' => $user->id,
            'playback_source_id' => $otherSource->id,
            'playback_source_item_id' => $tamperedItem->id,
            'status' => 'playing',
            'started_at' => now(),
            'last_position_seconds' => 12,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/v1/player/sessions/{$session->id}", [
                'position_seconds' => 120,
                'duration_seconds' => 600,
            ])
            ->assertNotFound();

        $this->assertSame(12, $session->fresh()->last_position_seconds);
    }

    public function test_completed_provider_session_automatically_tracks_watch_history(): void
    {
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Provider Movie',
            'runtime' => 110,
        ]);
        $item = $this->sourceItemFor($user);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", [
                'movie_id' => $movie->id,
                'confirm' => true,
            ])
            ->assertCreated();

        $playResponse = $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/play")
            ->assertCreated()
            ->assertJsonPath('session.sourceItemId', $item->id)
            ->assertJsonPath('playbackUrl', 'https://private.example.test/stream/movie');

        $sessionId = $playResponse->json('session.id');

        $this->actingAs($user)
            ->patchJson("/api/v1/player/sessions/{$sessionId}", [
                'position_seconds' => 6600,
                'duration_seconds' => 6600,
                'completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('session.status', 'completed');

        $this->assertDatabaseHas('movie_watches', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'source' => 'provider',
        ]);
    }

    public function test_user_without_provider_can_still_manually_track_watch_history(): void
    {
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Manual Movie',
            'runtime' => 100,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/watch", [
                'watched_at' => '2026-07-04T20:00:00Z',
                'runtime' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('watch.movie_id', $movie->id);

        $this->assertSame(0, PlaybackSource::forUser($user)->count());
        $this->assertSame(1, MovieWatch::forUser($user)->count());
    }

    public function test_deleting_provider_keeps_canonical_watch_history(): void
    {
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Linked Movie',
            'runtime' => 120,
        ]);
        $item = $this->sourceItemFor($user);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", [
                'movie_id' => $movie->id,
                'confirm' => true,
            ])
            ->assertCreated();

        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now(),
            'runtime' => 120,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/player/sources/{$item->playback_source_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('playback_sources', ['id' => $item->playback_source_id]);
        $this->assertDatabaseHas('movies', ['id' => $movie->id, 'user_id' => $user->id]);
        $this->assertSame(1, MovieWatch::forUser($user)->count());
    }

    public function test_provider_items_can_link_to_user_owned_episodes(): void
    {
        $user = $this->member();
        $show = \App\Models\Show::create([
            'user_id' => $user->id,
            'title' => 'Own Show',
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'external_source' => 'manual',
            'external_id' => 'own-episode-1',
        ]);
        $item = $this->sourceItemFor($user, 'episode');

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", [
                'episode_id' => $episode->id,
                'confirm' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('link.episode_id', $episode->id);
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
    }

    private function sourceItemFor(User $user, string $kind = 'movie'): PlaybackSourceItem
    {
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => $user->email.' source',
            'provider_type' => 'plex',
            'status' => 'active',
            'metadata' => ['status' => 'ok'],
        ]);

        return PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => $kind.'-1',
            'kind' => $kind,
            'title' => 'Source Item',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/'.$kind,
            'stream_url_hash' => hash('sha256', 'https://private.example.test/stream/'.$kind),
        ]);
    }
}
