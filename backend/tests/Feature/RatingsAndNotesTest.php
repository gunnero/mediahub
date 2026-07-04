<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatingsAndNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_rate_movie_episode_and_show(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'Severance']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'title' => 'Good News About Hell',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/rating", ['rating' => 9])
            ->assertOk()
            ->assertJsonPath('rating.rating', 9);

        $this->actingAs($user)
            ->postJson("/api/v1/library/shows/{$show->id}/rating", ['rating' => 8])
            ->assertOk()
            ->assertJsonPath('rating.rating', 8);

        $this->actingAs($user)
            ->postJson("/api/v1/library/episodes/{$episode->id}/rating", ['rating' => 10])
            ->assertOk()
            ->assertJsonPath('rating.rating', 10);

        $this->assertDatabaseHas('ratings', [
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'rating' => 9,
        ]);
        $this->assertDatabaseHas('ratings', [
            'user_id' => $user->id,
            'media_type' => 'show',
            'media_id' => $show->id,
            'rating' => 8,
        ]);
        $this->assertDatabaseHas('ratings', [
            'user_id' => $user->id,
            'media_type' => 'episode',
            'media_id' => $episode->id,
            'rating' => 10,
        ]);
    }

    public function test_user_can_add_private_notes_to_movie_show_and_episode(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Arrival']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'Dark']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'title' => 'Secrets',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/notes", ['body' => 'Rewatch with friends.'])
            ->assertCreated()
            ->assertJsonPath('note.body', 'Rewatch with friends.');

        $this->actingAs($user)
            ->postJson("/api/v1/library/shows/{$show->id}/notes", ['body' => 'Track family trees.'])
            ->assertCreated()
            ->assertJsonPath('note.body', 'Track family trees.');

        $this->actingAs($user)
            ->postJson("/api/v1/library/episodes/{$episode->id}/notes", ['body' => 'Great cold open.'])
            ->assertCreated()
            ->assertJsonPath('note.body', 'Great cold open.');

        $this->assertSame(3, $user->notes()->count());
        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'body' => 'Rewatch with friends.',
        ]);
    }

    public function test_user_cannot_rate_or_note_another_users_media(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherMovie = Movie::create(['user_id' => $otherUser->id, 'title' => 'Other Movie']);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$otherMovie->id}/rating", ['rating' => 7])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$otherMovie->id}/notes", ['body' => 'Should not save.'])
            ->assertNotFound();

        $this->assertDatabaseMissing('ratings', [
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $otherMovie->id,
        ]);
        $this->assertDatabaseMissing('notes', [
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $otherMovie->id,
        ]);
    }

    public function test_provider_deletion_keeps_ratings_and_notes(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Linked Movie']);
        $item = $this->sourceItemFor($user);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", ['movie_id' => $movie->id])
            ->assertCreated();
        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/rating", ['rating' => 9])
            ->assertOk();
        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/notes", ['body' => 'Permanent note.'])
            ->assertCreated();

        $this->actingAs($user)
            ->deleteJson("/api/v1/player/sources/{$item->playback_source_id}")
            ->assertNoContent();

        $this->assertDatabaseHas('ratings', [
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'rating' => 9,
        ]);
        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'body' => 'Permanent note.',
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
