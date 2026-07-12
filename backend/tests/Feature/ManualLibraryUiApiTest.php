<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualLibraryUiApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_movie_detail_includes_annotations_history_and_safe_provider_status(): void
    {
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Heat',
            'runtime' => 170,
            'poster_url' => 'https://images.example.test/heat.jpg',
        ]);
        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now()->subDay(),
            'runtime' => 170,
            'source' => 'manual',
        ]);
        Rating::create([
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'rating' => 9,
        ]);
        Note::create([
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'body' => 'Watch the diner scene again.',
        ]);
        $item = $this->sourceItemFor($user);
        MediaLink::create([
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
            'movie_id' => $movie->id,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/library/movies/{$movie->id}")
            ->assertOk()
            ->assertJsonPath('item.kind', 'movie')
            ->assertJsonPath('item.title', 'Heat')
            ->assertJsonPath('item.watched', true)
            ->assertJsonPath('item.rating.rating', 9)
            ->assertJsonPath('item.notes.0.body', 'Watch the diner scene again.')
            ->assertJsonPath('item.watchHistory.0.source', 'manual')
            ->assertJsonPath('item.provider.linked', true)
            ->assertJsonPath('item.provider.linkedItemsCount', 1);

        $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('stream_url', $payload);
        $this->assertStringNotContainsString('streamUrl', $payload);
        $this->assertStringNotContainsString('playlist_url', $payload);
        $this->assertStringNotContainsString('provider_secret', $payload);
        $this->assertStringNotContainsString('https://private.example.test', $payload);
    }

    public function test_episode_detail_is_user_scoped_and_includes_show_context(): void
    {
        $user = $this->member();
        $show = Show::create(['user_id' => $user->id, 'title' => 'Severance']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Good News About Hell',
            'runtime' => 57,
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episode->id,
            'watched_at' => now(),
            'runtime' => 57,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/library/episodes/{$episode->id}")
            ->assertOk()
            ->assertJsonPath('item.kind', 'episode')
            ->assertJsonPath('item.showId', $show->id)
            ->assertJsonPath('item.showTitle', 'Severance')
            ->assertJsonPath('item.title', 'Good News About Hell')
            ->assertJsonPath('item.subtitle', 'S1 E1')
            ->assertJsonPath('item.watched', true);
    }

    public function test_user_cannot_access_another_users_detail_or_annotations(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherMovie = Movie::create(['user_id' => $otherUser->id, 'title' => 'Other Movie']);
        $otherNote = Note::create([
            'user_id' => $otherUser->id,
            'media_type' => 'movie',
            'media_id' => $otherMovie->id,
            'body' => 'Private',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/library/movies/{$otherMovie->id}")
            ->assertNotFound();

        $this->actingAs($user)
            ->patchJson("/api/v1/library/notes/{$otherNote->id}", ['body' => 'Nope'])
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson("/api/v1/library/notes/{$otherNote->id}")
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson("/api/v1/library/movies/{$otherMovie->id}/rating")
            ->assertNotFound();
    }

    public function test_rating_can_be_cleared_and_notes_can_be_updated_or_deleted(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Arrival']);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/rating", ['rating' => 10])
            ->assertOk();

        $this->actingAs($user)
            ->deleteJson("/api/v1/library/movies/{$movie->id}/rating")
            ->assertNoContent();

        $this->assertDatabaseMissing('ratings', [
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
        ]);

        $noteId = $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/notes", ['body' => 'Original note.'])
            ->assertCreated()
            ->json('note.id');

        $this->actingAs($user)
            ->patchJson("/api/v1/library/notes/{$noteId}", ['body' => 'Updated note.'])
            ->assertOk()
            ->assertJsonPath('note.body', 'Updated note.');

        $this->assertDatabaseHas('notes', [
            'id' => $noteId,
            'user_id' => $user->id,
            'body' => 'Updated note.',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/library/notes/{$noteId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('notes', ['id' => $noteId]);
    }

    public function test_manual_movie_and_episode_watches_append_rewatches_and_remove_only_the_latest(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Manual Movie']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'Manual Show']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'title' => 'Manual Episode',
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($user)
                ->postJson("/api/v1/library/movies/{$movie->id}/watch")
                ->assertCreated();
            $this->actingAs($user)
                ->postJson("/api/v1/library/episodes/{$episode->id}/watch")
                ->assertCreated();
        }

        $this->assertSame(2, MovieWatch::forUser($user)->where('movie_id', $movie->id)->where('source', 'manual')->count());
        $this->assertSame(2, EpisodeWatch::forUser($user)->where('episode_id', $episode->id)->where('source', 'manual')->count());

        $this->actingAs($user)
            ->getJson("/api/v1/library/movies/{$movie->id}")
            ->assertOk()
            ->assertJsonPath('item.watchedCount', 2)
            ->assertJsonPath('item.watchHistory.0.watchNumber', 2)
            ->assertJsonPath('item.watchHistory.1.watchNumber', 1);

        $this->actingAs($user)
            ->deleteJson("/api/v1/library/movies/{$movie->id}/watch")
            ->assertNoContent();
        $this->actingAs($user)
            ->deleteJson("/api/v1/library/episodes/{$episode->id}/watch")
            ->assertNoContent();

        $this->assertSame(1, MovieWatch::forUser($user)->where('movie_id', $movie->id)->where('source', 'manual')->count());
        $this->assertSame(1, EpisodeWatch::forUser($user)->where('episode_id', $episode->id)->where('source', 'manual')->count());

        $this->actingAs($user)->deleteJson("/api/v1/library/movies/{$movie->id}/watch")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/v1/library/episodes/{$episode->id}/watch")->assertNoContent();
        $this->assertSame(0, MovieWatch::forUser($user)->where('movie_id', $movie->id)->where('source', 'manual')->count());
        $this->assertSame(0, EpisodeWatch::forUser($user)->where('episode_id', $episode->id)->where('source', 'manual')->count());
    }

    public function test_unwatch_only_removes_manual_rows_and_keeps_provider_history(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Permanent Movie']);

        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now()->subDay(),
            'runtime' => 100,
            'source' => 'provider',
        ]);
        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now(),
            'runtime' => 100,
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/library/movies/{$movie->id}/watch")
            ->assertNoContent();

        $this->assertSame(1, MovieWatch::forUser($user)->where('movie_id', $movie->id)->count());
        $this->assertDatabaseHas('movie_watches', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'source' => 'provider',
        ]);
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
    }

    private function sourceItemFor(User $user): PlaybackSourceItem
    {
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => $user->email.' source',
            'provider_type' => 'plex',
            'status' => 'active',
            'metadata' => ['provider_url' => 'https://private.example.test/provider'],
            'settings' => ['provider_secret' => 'secret-token', 'playlist_url' => 'https://private.example.test/playlist'],
        ]);

        return PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => 'movie-1',
            'kind' => 'movie',
            'title' => 'Provider Movie',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/movie',
            'stream_url_hash' => hash('sha256', 'https://private.example.test/stream/movie'),
        ]);
    }
}
