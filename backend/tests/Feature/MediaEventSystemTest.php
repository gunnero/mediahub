<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MediaEvent;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\User;
use App\Services\DashboardPayloadService;
use App\Services\MediaEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaEventSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_service_records_user_scoped_events_and_strips_sensitive_metadata(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);

        $event = app(MediaEventService::class)->record($user, 'movie.watched', $movie, [
            'title' => 'Heat',
            'stream_url' => 'https://private.example.test/stream.m3u8',
            'nested' => [
                'api_key' => 'secret-key',
                'safe' => 'kept',
            ],
        ], 'manual');

        $this->assertInstanceOf(MediaEvent::class, $event);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame(Movie::class, $event->subject_type);
        $this->assertSame($movie->id, $event->subject_id);
        $this->assertSame('manual', $event->source);
        $this->assertSame('Heat', $event->metadata['title']);
        $this->assertSame('kept', $event->metadata['nested']['safe']);
        $this->assertArrayNotHasKey('stream_url', $event->metadata);
        $this->assertArrayNotHasKey('api_key', $event->metadata['nested']);
    }

    public function test_media_events_api_is_user_scoped_and_filterable(): void
    {
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);
        Movie::create(['user_id' => $otherUser->id, 'title' => 'Other Movie']);

        app(MediaEventService::class)->record($user, 'movie.watched', $movie, [
            'title' => 'Heat',
        ], 'manual');
        app(MediaEventService::class)->record($user, 'playback.progressed', null, [
            'title' => 'Heat',
            'position_seconds' => 120,
        ], 'player');
        app(MediaEventService::class)->record($otherUser, 'movie.watched', null, [
            'title' => 'Other Movie',
        ], 'manual');

        $this->actingAs($user)
            ->getJson('/api/v1/media-events?event_type=movie.watched&source=manual')
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.eventType', 'movie.watched')
            ->assertJsonPath('events.0.title', 'Watched Heat')
            ->assertJsonMissing(['title' => 'Other Movie']);

        $this->actingAs($user)
            ->getJson('/api/v1/media-events/recent')
            ->assertOk()
            ->assertJsonCount(1, 'events');

        $this->actingAs($user)
            ->getJson('/api/v1/media-events?event_type=playback.progressed')
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.eventType', 'playback.progressed');
    }

    public function test_existing_actions_record_meaningful_events(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat', 'runtime' => 170]);
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'NAS',
            'provider_type' => 'manual',
            'status' => 'active',
        ]);
        $item = PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => 'heat-source',
            'kind' => 'movie',
            'title' => 'Heat Source',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/heat.m3u8',
            'stream_url_hash' => hash('sha256', 'https://private.example.test/heat.m3u8'),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/watch")
            ->assertCreated();
        $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/rating", ['rating' => 10])
            ->assertOk();
        $noteId = $this->actingAs($user)
            ->postJson("/api/v1/library/movies/{$movie->id}/notes", ['body' => 'Private note.'])
            ->assertCreated()
            ->json('note.id');
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
        $this->actingAs($user)
            ->patchJson("/api/v1/player/sessions/{$sessionId}", [
                'position_seconds' => 10200,
                'duration_seconds' => 10200,
                'completed' => true,
            ])
            ->assertOk();
        $this->actingAs($user)
            ->deleteJson("/api/v1/library/notes/{$noteId}")
            ->assertNoContent();

        foreach ([
            'movie.watched',
            'rating.created',
            'note.created',
            'provider.item.linked',
            'playback.started',
            'playback.completed',
            'note.deleted',
        ] as $eventType) {
            $this->assertDatabaseHas('media_events', [
                'user_id' => $user->id,
                'event_type' => $eventType,
            ]);
        }

        $events = MediaEvent::forUser($user)->get();
        $encoded = json_encode($events->map->metadata->all(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('https://private.example.test', $encoded);
        $this->assertStringNotContainsString('stream_url', $encoded);
    }

    public function test_dashboard_payload_contains_safe_timeline_summary(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);

        app(MediaEventService::class)->record($user, 'movie.watched', $movie, [
            'title' => 'Heat',
            'provider_url' => 'https://private.example.test/provider',
        ], 'manual');
        app(MediaEventService::class)->record($user, 'playback.progressed', $movie, [
            'title' => 'Heat',
            'position_seconds' => 120,
        ], 'player');

        $payload = app(DashboardPayloadService::class)->forUser($user);
        $encoded = json_encode($payload['timeline'], JSON_THROW_ON_ERROR);

        $this->assertSame('Watched Heat', $payload['timeline']['recent'][0]['title']);
        $this->assertSame(1, $payload['timeline']['todaySummary']['total']);
        $this->assertSame(1, $payload['timeline']['thisWeekSummary']['total']);
        $this->assertStringNotContainsString('playback.progressed', $encoded);
        $this->assertStringNotContainsString('provider_url', $encoded);
        $this->assertStringNotContainsString('private.example.test', $encoded);
    }

    public function test_deleting_provider_keeps_existing_media_events(): void
    {
        $user = $this->member();
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'NAS',
            'provider_type' => 'manual',
            'status' => 'active',
        ]);
        $item = PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => 'source-item',
            'kind' => 'movie',
            'title' => 'Source Item',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/source.m3u8',
            'stream_url_hash' => hash('sha256', 'https://private.example.test/source.m3u8'),
        ]);

        app(MediaEventService::class)->record($user, 'provider.item.linked', $item, [
            'title' => 'Source Item',
        ], 'provider');

        MediaLink::create([
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
            'linked_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/player/sources/{$source->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('media_events', [
            'user_id' => $user->id,
            'event_type' => 'provider.item.linked',
            'subject_type' => PlaybackSourceItem::class,
            'subject_id' => $item->id,
        ]);
        $this->assertDatabaseHas('media_events', [
            'user_id' => $user->id,
            'event_type' => 'provider.deleted',
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
}
