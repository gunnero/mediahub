<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\PlaybackProgress;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchHistoryInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlinked_provider_playback_saves_source_progress_without_canonical_watch(): void
    {
        $user = $this->member();
        $item = $this->sourceItemFor($user);

        $sessionId = $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/play")
            ->assertCreated()
            ->json('session.id');

        $this->actingAs($user)
            ->patchJson("/api/v1/player/sessions/{$sessionId}", [
                'position_seconds' => 4200,
                'duration_seconds' => 4200,
                'completed' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('playback_progress', [
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
            'completed' => true,
            'movie_id' => null,
            'episode_id' => null,
        ]);
        $this->assertSame(0, MovieWatch::forUser($user)->count());
        $this->assertSame(0, EpisodeWatch::forUser($user)->count());
    }

    public function test_linked_provider_playback_creates_one_canonical_watch_per_session(): void
    {
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Canonical Movie',
            'runtime' => 95,
        ]);
        $item = $this->sourceItemFor($user);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", [
                'movie_id' => $movie->id,
                'confirm' => true,
            ])
            ->assertCreated();

        $sessionId = $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/play")
            ->assertCreated()
            ->json('session.id');

        foreach ([1, 2] as $attempt) {
            $this->actingAs($user)
                ->patchJson("/api/v1/player/sessions/{$sessionId}", [
                    'position_seconds' => 5700,
                    'duration_seconds' => 5700,
                    'completed' => true,
                ])
                ->assertOk();
        }

        $this->assertSame(1, MovieWatch::forUser($user)->where('movie_id', $movie->id)->count());
        $this->assertDatabaseHas('movie_watches', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'source' => 'provider',
        ]);
    }

    public function test_provider_deletion_keeps_canonical_activity_annotations_and_dashboard_stats(): void
    {
        $user = $this->member();
        $show = Show::create(['user_id' => $user->id, 'title' => 'Linked Show']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'title' => 'Linked Episode',
            'runtime' => 45,
        ]);
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Linked Movie',
            'runtime' => 110,
        ]);
        $item = $this->sourceItemFor($user);

        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now(),
            'runtime' => 110,
            'source' => 'provider',
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episode->id,
            'watched_at' => now(),
            'runtime' => 45,
            'source' => 'provider',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", [
                'movie_id' => $movie->id,
                'confirm' => true,
            ])
            ->assertCreated();
        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/rating", ['rating' => 10])
            ->assertOk();
        $this->actingAs($user)
            ->postJson("/api/v1/library/episodes/{$episode->id}/notes", ['body' => 'Permanent episode note.'])
            ->assertCreated();

        $this->actingAs($user)
            ->deleteJson("/api/v1/player/sources/{$item->playback_source_id}")
            ->assertNoContent();

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.moviesWatched', 1)
            ->assertJsonPath('stats.episodesWatched', 1)
            ->assertJsonPath('stats.ratingsCount', 1)
            ->assertJsonPath('stats.notesCount', 1)
            ->assertJsonPath('stats.linkedProviderItemsCount', 0);

        $this->assertDatabaseHas('ratings', ['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id]);
        $this->assertDatabaseHas('notes', ['user_id' => $user->id, 'media_type' => 'episode', 'media_id' => $episode->id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'playback_source.deleted',
            'target_user_id' => $user->id,
        ]);
    }

    public function test_dashboard_payload_has_player_counts_and_no_provider_urls_or_secrets(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Dashboard Movie']);
        $linkedItem = $this->sourceItemFor($user, 'linked-1');
        $unlinkedItem = $this->sourceItemFor($user, 'unlinked-1');

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$linkedItem->id}/link", [
                'movie_id' => $movie->id,
                'confirm' => true,
            ])
            ->assertCreated();

        PlaybackProgress::create([
            'user_id' => $user->id,
            'playback_source_item_id' => $unlinkedItem->id,
            'position_seconds' => 300,
            'duration_seconds' => 1200,
            'completed' => false,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/rating", ['rating' => 7])
            ->assertOk();
        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/notes", ['body' => 'Dashboard note.'])
            ->assertCreated();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.linkedProviderItemsCount', 1)
            ->assertJsonPath('stats.unlinkedProviderItemsCount', 1)
            ->assertJsonPath('stats.unsyncedSourceOnlyProgressCount', 1)
            ->assertJsonPath('stats.ratingsCount', 1)
            ->assertJsonPath('stats.notesCount', 1);

        $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('stream_url', $payload);
        $this->assertStringNotContainsString('streamUrl', $payload);
        $this->assertStringNotContainsString('playbackUrl', $payload);
        $this->assertStringNotContainsString('playlist_url', $payload);
        $this->assertStringNotContainsString('provider_secret', $payload);
        $this->assertStringNotContainsString('https://private.example.test', $payload);
    }

    public function test_dashboard_ignores_media_links_that_do_not_belong_to_the_source_item_owner(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $item = $this->sourceItemFor($user);
        $otherMovie = Movie::create(['user_id' => $otherUser->id, 'title' => 'Other Movie']);

        MediaLink::create([
            'user_id' => $otherUser->id,
            'playback_source_item_id' => $item->id,
            'movie_id' => $otherMovie->id,
            'linked_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('player.sourceItems.0.linked', false)
            ->assertJsonPath('stats.linkedProviderItemsCount', 0)
            ->assertJsonPath('stats.unlinkedProviderItemsCount', 1);
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
    }

    private function sourceItemFor(User $user, string $externalId = 'movie-1'): PlaybackSourceItem
    {
        $source = PlaybackSource::firstOrCreate([
            'user_id' => $user->id,
            'name' => $user->email.' source',
        ], [
            'provider_type' => 'plex',
            'status' => 'active',
            'metadata' => ['provider_url' => 'https://private.example.test/provider'],
            'settings' => ['provider_secret' => 'secret-token', 'playlist_url' => 'https://private.example.test/playlist'],
        ]);

        return PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => $externalId,
            'kind' => 'movie',
            'title' => 'Provider Movie',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/'.$externalId,
            'stream_url_hash' => hash('sha256', 'https://private.example.test/stream/'.$externalId),
        ]);
    }
}
