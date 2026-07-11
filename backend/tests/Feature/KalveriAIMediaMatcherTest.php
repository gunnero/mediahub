<?php

namespace Tests\Feature;

use App\Enums\MediaEventType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use App\Services\SafeAIMatchingPayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KalveriAIMediaMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_kalveri_ai_disabled_falls_back_safely_without_linking(): void
    {
        Config::set('kalveri_ai.enabled', false);
        $user = $this->member();
        $item = $this->sourceItemFor($user, 'Unknown Archive Item');

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/ai-match")
            ->assertOk()
            ->assertJsonPath('suggestion.status', 'disabled')
            ->assertJsonPath('suggestion.requiresConfirmation', true);

        $this->assertDatabaseMissing('media_links', [
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
        ]);
    }

    public function test_provider_ai_match_payload_excludes_forbidden_fields_and_requires_confirmation(): void
    {
        Config::set('kalveri_ai.enabled', true);
        Config::set('kalveri_ai.base_url', 'https://kalveri-ai.example.test');
        Config::set('kalveri_ai.api_key', 'kalveri-ai-test-key');
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Heat',
            'tmdb_id' => 949,
            'release_date' => '1995-12-15',
        ]);
        $item = $this->sourceItemFor($user, 'Heat 2160p private cut');

        Http::fake([
            'kalveri-ai.example.test/*' => Http::response([
                'suggestion' => [
                    'media_type' => 'movie',
                    'candidate_id' => $movie->id,
                    'confidence' => 0.82,
                    'reason' => 'Title and year align with the local candidate.',
                    'stream_url' => 'must-not-return',
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/ai-match")
            ->assertOk()
            ->assertJsonPath('suggestion.status', 'suggested')
            ->assertJsonPath('suggestion.source', 'kalveri_ai')
            ->assertJsonPath('suggestion.mediaType', 'movie')
            ->assertJsonPath('suggestion.candidateId', $movie->id)
            ->assertJsonPath('suggestion.requiresConfirmation', true)
            ->assertJsonMissingPath('suggestion.stream_url');

        Http::assertSent(function ($request): bool {
            $encoded = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return $request->hasHeader('Authorization', 'Bearer kalveri-ai-test-key')
                && ! str_contains($encoded, 'stream_url')
                && ! str_contains($encoded, 'provider_url')
                && ! str_contains($encoded, 'playlist_url')
                && ! str_contains($encoded, 'credential')
                && ! str_contains($encoded, 'kalveri-ai-test-key')
                && ! str_contains($encoded, 'https://private.example.test');
        });

        $item->refresh();
        $this->assertSame('suggested', $item->metadata['ai_match_suggestion']['status']);
        $this->assertDatabaseMissing('media_links', [
            'playback_source_item_id' => $item->id,
        ]);
    }

    public function test_user_cannot_ai_match_another_users_source_item(): void
    {
        Config::set('kalveri_ai.enabled', true);
        Config::set('kalveri_ai.base_url', 'https://kalveri-ai.example.test');
        Config::set('kalveri_ai.api_key', 'kalveri-ai-test-key');
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $otherItem = $this->sourceItemFor($otherUser, 'Other Item');
        Http::fake();

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$otherItem->id}/ai-match")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_provider_ai_match_failure_does_not_break_flow(): void
    {
        Config::set('kalveri_ai.enabled', true);
        Config::set('kalveri_ai.base_url', 'https://kalveri-ai.example.test');
        Config::set('kalveri_ai.api_key', 'kalveri-ai-test-key');
        $user = $this->member();
        $item = $this->sourceItemFor($user, 'Uncertain File');

        Http::fake([
            'kalveri-ai.example.test/*' => Http::response(['error' => 'offline'], 500),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/player/items/{$item->id}/ai-match")
            ->assertOk()
            ->assertJsonPath('suggestion.requiresConfirmation', true);
    }

    public function test_metadata_review_ai_suggestion_does_not_auto_apply_and_apply_command_confirms(): void
    {
        Config::set('kalveri_ai.enabled', true);
        Config::set('kalveri_ai.base_url', 'https://kalveri-ai.example.test');
        Config::set('kalveri_ai.api_key', 'kalveri-ai-test-key');
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'tmdb-test-key');
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Timeless',
            'tmdb_id' => 66786,
            'metadata_refreshed_at' => now(),
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 2,
            'episode_number' => 11,
            'title' => 'Review Queue Episode',
            'metadata_failure_count' => 3,
            'last_metadata_failure_reason' => 'tmdb_404',
            'metadata_failed_at' => now(),
        ]);

        Http::fake([
            'kalveri-ai.example.test/*' => Http::response([
                'suggestion' => [
                    'tmdb_season' => 2,
                    'tmdb_episode' => 9,
                    'confidence' => 0.78,
                    'reason' => 'The local row appears offset by two episodes.',
                    'api_key' => 'must-not-store',
                ],
            ]),
            'api.themoviedb.org/3/tv/66786/season/2/episode/9*' => Http::response([
                'id' => 98765,
                'name' => 'Corrected Episode',
                'overview' => 'Confirmed public metadata.',
                'runtime' => 42,
                'external_ids' => [],
            ]),
        ]);

        $this->artisan('mediahub:ai-match-review-episode', ['episode_id' => $episode->id])
            ->expectsOutput('episode_id: '.$episode->id)
            ->expectsOutput('show_id: '.$show->id)
            ->expectsOutput('tmdb_season: 2')
            ->expectsOutput('tmdb_episode: 9')
            ->assertExitCode(0);

        $episode->refresh();
        $this->assertNull($episode->tmdb_id);
        $this->assertSame(2, $episode->metadata['ai_review_suggestion']['tmdbSeason']);
        $this->assertArrayNotHasKey('api_key', $episode->metadata['ai_review_suggestion']);

        $this->artisan('mediahub:apply-review-match', [
            'episode_id' => $episode->id,
            '--season' => 2,
            '--episode' => 9,
        ])
            ->expectsOutput('episode_id: '.$episode->id)
            ->expectsOutput('tmdb_id: 98765')
            ->expectsOutput('metadata_review_status: manually_matched')
            ->assertExitCode(0);

        $episode->refresh();
        $this->assertSame(98765, $episode->tmdb_id);
        $this->assertSame('manually_matched', $episode->metadata_review_status);
        $this->assertDatabaseHas('media_events', [
            'user_id' => $user->id,
            'event_type' => MediaEventType::AIMatchConfirmed->value,
        ]);
    }

    public function test_payload_sanitizer_recursively_removes_forbidden_keys(): void
    {
        $sanitized = app(SafeAIMatchingPayloadService::class)->sanitize([
            'normalized_title' => 'heat',
            'stream_url' => 'https://private.example.test/stream',
            'nested' => [
                'api_key' => 'key',
                'candidate_id' => 42,
                'credential_blob' => 'secret',
            ],
        ]);

        $this->assertArrayHasKey('normalized_title', $sanitized);
        $this->assertArrayNotHasKey('stream_url', $sanitized);
        $this->assertSame(['candidate_id' => 42], $sanitized['nested']);
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
    }

    private function sourceItemFor(User $user, string $title): PlaybackSourceItem
    {
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => $user->email.' source',
            'provider_type' => 'manual',
            'status' => 'active',
            'metadata' => ['provider_url' => 'https://private.example.test/provider'],
            'settings' => ['provider_secret' => 'secret-token', 'playlist_url' => 'https://private.example.test/playlist'],
        ]);

        return PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => str($title)->slug()->toString(),
            'kind' => 'movie',
            'title' => $title,
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/'.str($title)->slug(),
            'stream_url_hash' => hash('sha256', 'https://private.example.test/stream/'.str($title)->slug()),
        ]);
    }
}
