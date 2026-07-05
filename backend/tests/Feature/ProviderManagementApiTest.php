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

class ProviderManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_manual_provider_and_source_item_without_leaking_stream_url(): void
    {
        $user = $this->member();

        $sourceResponse = $this->actingAs($user)
            ->postJson('/api/v1/player/sources', [
                'name' => 'My NAS',
                'provider_type' => 'manual',
                'legal_confirmed' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('source.name', 'My NAS')
            ->assertJsonPath('source.providerType', 'manual')
            ->assertJsonPath('source.status', 'active')
            ->assertJsonMissingPath('source.settings');

        $sourceId = $sourceResponse->json('source.id');

        $this->actingAs($user)
            ->postJson("/api/v1/player/sources/{$sourceId}/items", [
                'title' => 'Private Movie File',
                'kind' => 'movie',
                'stream_url' => 'https://media.example.test/private/movie.m3u8',
            ])
            ->assertCreated()
            ->assertJsonPath('item.title', 'Private Movie File')
            ->assertJsonPath('item.kind', 'movie')
            ->assertJsonPath('item.linked', false)
            ->assertJsonMissingPath('item.stream_url')
            ->assertJsonMissingPath('item.streamUrl')
            ->assertJsonMissingPath('item.playbackUrl');

        $item = PlaybackSourceItem::query()->where('title', 'Private Movie File')->firstOrFail();

        $this->assertSame($user->id, $item->user_id);
        $this->assertSame($sourceId, $item->playback_source_id);
        $this->assertSame(hash('sha256', 'https://media.example.test/private/movie.m3u8'), $item->stream_url_hash);
        $this->assertNotSame('https://media.example.test/private/movie.m3u8', $item->getRawOriginal('stream_url'));
    }

    public function test_provider_creation_requires_legal_confirmation(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->postJson('/api/v1/player/sources', [
                'name' => 'Unconfirmed source',
                'provider_type' => 'manual',
                'legal_confirmed' => false,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('playback_sources', [
            'user_id' => $user->id,
            'name' => 'Unconfirmed source',
        ]);
    }

    public function test_user_can_list_and_search_only_their_own_source_items(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $ownSource = $this->sourceFor($user, 'Own Manual Source');
        $otherSource = $this->sourceFor($otherUser, 'Other Manual Source');
        $moonItem = $this->sourceItemFor($user, $ownSource, 'Moon Archive');
        $this->sourceItemFor($user, $ownSource, 'Heat Archive');
        $this->sourceItemFor($otherUser, $otherSource, 'Moon Archive');

        $this->actingAs($user)
            ->getJson('/api/v1/player/items?q=moon')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $moonItem->id)
            ->assertJsonPath('items.0.sourceName', 'Own Manual Source')
            ->assertJsonPath('items.0.sourceStatus', 'active')
            ->assertJsonPath('items.0.linked', false)
            ->assertJsonMissing(['sourceName' => 'Other Manual Source'])
            ->assertJsonMissingPath('items.0.stream_url')
            ->assertJsonMissingPath('items.0.streamUrl');
    }

    public function test_linking_requires_confirmation_and_can_be_unlinked(): void
    {
        $user = $this->member();
        $source = $this->sourceFor($user);
        $item = $this->sourceItemFor($user, $source, 'Heat Source');
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Heat',
            'runtime' => 170,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/player/link-targets?q=heat')
            ->assertOk()
            ->assertJsonPath('targets.0.type', 'movie')
            ->assertJsonPath('targets.0.id', $movie->id)
            ->assertJsonPath('targets.0.title', 'Heat');

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", [
                'movie_id' => $movie->id,
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/link", [
                'movie_id' => $movie->id,
                'confirm' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('link.movie_id', $movie->id);

        $this->assertDatabaseHas('media_links', [
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
            'movie_id' => $movie->id,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/player/items/{$item->id}/link")
            ->assertNoContent();

        $this->assertDatabaseMissing('media_links', [
            'playback_source_item_id' => $item->id,
        ]);
    }

    public function test_link_targets_include_user_owned_shows_and_episodes_only(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $show = Show::create(['user_id' => $user->id, 'title' => 'Manifest']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'title' => 'Pilot',
            'season_number' => 1,
            'episode_number' => 1,
        ]);
        Show::create(['user_id' => $otherUser->id, 'title' => 'Manifest Other']);

        $this->actingAs($user)
            ->getJson('/api/v1/player/link-targets?q=manifest')
            ->assertOk()
            ->assertJsonFragment(['type' => 'show', 'id' => $show->id, 'title' => 'Manifest'])
            ->assertJsonMissing(['title' => 'Manifest Other']);

        $this->actingAs($user)
            ->getJson('/api/v1/player/link-targets?q=pilot&type=episode')
            ->assertOk()
            ->assertJsonCount(1, 'targets')
            ->assertJsonPath('targets.0.type', 'episode')
            ->assertJsonPath('targets.0.id', $episode->id);
    }

    public function test_user_can_disable_provider_without_deleting_provider_rows(): void
    {
        $user = $this->member();
        $source = $this->sourceFor($user);
        $this->sourceItemFor($user, $source, 'Private Movie File');

        $this->actingAs($user)
            ->patchJson("/api/v1/player/sources/{$source->id}", [
                'status' => 'disabled',
            ])
            ->assertOk()
            ->assertJsonPath('source.status', 'disabled');

        $this->assertDatabaseHas('playback_sources', [
            'id' => $source->id,
            'user_id' => $user->id,
            'status' => 'disabled',
        ]);
        $this->assertSame(1, PlaybackSourceItem::forUser($user)->count());

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('player.enabled', false)
            ->assertJsonPath('player.emptyState', 'Attach your own source to enable playback and automatic tracking.');
    }

    public function test_user_cannot_manage_another_users_provider_or_link(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherSource = $this->sourceFor($otherUser);
        $otherItem = $this->sourceItemFor($otherUser, $otherSource, 'Other Source');

        $this->actingAs($user)
            ->postJson("/api/v1/player/sources/{$otherSource->id}/items", [
                'title' => 'Bad item',
                'kind' => 'movie',
                'stream_url' => 'https://media.example.test/bad.m3u8',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->patchJson("/api/v1/player/sources/{$otherSource->id}", ['status' => 'disabled'])
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson("/api/v1/player/items/{$otherItem->id}/link")
            ->assertNotFound();
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
    }

    private function sourceFor(User $user, string $name = 'Manual Source'): PlaybackSource
    {
        return PlaybackSource::create([
            'user_id' => $user->id,
            'name' => $name,
            'provider_type' => 'manual',
            'status' => 'active',
            'metadata' => ['legal_confirmed_at' => now()->toIso8601String()],
        ]);
    }

    private function sourceItemFor(User $user, PlaybackSource $source, string $title): PlaybackSourceItem
    {
        return PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => str($title)->slug()->toString(),
            'kind' => 'movie',
            'title' => $title,
            'status' => 'available',
            'stream_url' => 'https://media.example.test/'.str($title)->slug()->toString().'.m3u8',
            'stream_url_hash' => hash('sha256', 'https://media.example.test/'.str($title)->slug()->toString().'.m3u8'),
        ]);
    }
}
