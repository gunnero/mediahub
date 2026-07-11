<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoveryProviderCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'tmdb-test-key');
        Config::set('mediahub_providers.sync_limit', 100);
        Config::set('mediahub_providers.series_detail_limit', 0);
    }

    public function test_external_discovery_searches_movies_and_shows_and_marks_existing_items(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat', 'tmdb_id' => 949]);
        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([
                'page' => 1, 'total_pages' => 1, 'total_results' => 1,
                'results' => [['id' => 949, 'title' => 'Heat', 'original_title' => 'Heat', 'release_date' => '1995-12-15', 'poster_path' => '/heat.jpg', 'backdrop_path' => '/heat-bg.jpg', 'overview' => 'Crime saga.', 'genre_ids' => [80, 18]]],
            ]),
            'api.themoviedb.org/3/search/tv*' => Http::response([
                'page' => 1, 'total_pages' => 1, 'total_results' => 1,
                'results' => [['id' => 95396, 'name' => 'Severance', 'original_name' => 'Severance', 'first_air_date' => '2022-02-18', 'poster_path' => '/severance.jpg', 'overview' => 'Work-life separation.', 'genre_ids' => [18, 9648]]],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/discover/search?query=severance&type=all&page=1')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('items.0.media_type', 'movie')
            ->assertJsonPath('items.0.already_in_library', true)
            ->assertJsonPath('items.0.existing_library_id', $movie->id)
            ->assertJsonPath('items.1.media_type', 'show')
            ->assertJsonPath('items.1.already_in_library', false)
            ->assertJsonPath('items.1.genres.0', 'Drama');
    }

    public function test_discovered_movie_and_show_are_added_once_and_scoped_to_user(): void
    {
        $user = $this->member();
        $other = $this->member('other@example.test');
        Http::fake([
            'api.themoviedb.org/3/movie/949*' => Http::response($this->movieDetails()),
            'api.themoviedb.org/3/tv/95396*' => Http::response($this->showDetails()),
        ]);

        $this->actingAs($user)->postJson('/api/v1/discover/movies/949/add', ['action' => 'watchlist'])->assertCreated()->assertJsonPath('item.watchlist', true);
        $this->actingAs($user)->postJson('/api/v1/discover/movies/949/add', ['action' => 'library'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/discover/shows/95396/add', ['action' => 'watchlist'])->assertCreated()->assertJsonPath('item.watchlist', true);

        $this->assertSame(1, Movie::forUser($user)->where('tmdb_id', 949)->count());
        $this->assertSame(1, Show::forUser($user)->where('tmdb_id', 95396)->count());
        $this->assertTrue(Movie::forUser($user)->where('tmdb_id', 949)->firstOrFail()->is_to_watch);
        $this->assertTrue(Show::forUser($user)->where('tmdb_id', 95396)->firstOrFail()->followed);
        $this->assertSame(0, Movie::forUser($other)->count());
        $this->assertDatabaseHas('media_events', ['user_id' => $user->id, 'event_type' => 'movie.added']);
        $this->assertDatabaseHas('media_events', ['user_id' => $user->id, 'event_type' => 'show.added']);
    }

    public function test_discovery_is_failure_safe_when_tmdb_is_disabled(): void
    {
        Config::set('tmdb.enabled', false);
        $user = $this->member();

        $this->actingAs($user)
            ->getJson('/api/v1/discover/search?query=heat')
            ->assertOk()
            ->assertJsonPath('status', 'disabled')
            ->assertJsonCount(0, 'items');

        Http::assertNothingSent();
    }

    public function test_provider_credentials_are_encrypted_and_never_serialized(): void
    {
        $user = $this->member();
        $response = $this->actingAs($user)->postJson('/api/v1/providers', [
            'name' => 'Private Catalog',
            'provider_type' => 'xtream',
            'base_url' => 'https://provider.example.test',
            'username' => 'private-user',
            'password' => 'private-password',
            'xmltv_url' => 'https://provider.example.test/guide.xml',
            'legal_confirmed' => true,
        ])->assertCreated()->assertJsonPath('provider.credentialsConfigured', true);

        $source = PlaybackSource::forUser($user)->firstOrFail();
        $raw = (string) DB::table('playback_sources')->where('id', $source->id)->value('settings');
        $encoded = $response->getContent();

        $this->assertStringNotContainsString('private-user', $raw);
        $this->assertStringNotContainsString('private-password', $raw);
        $this->assertStringNotContainsString('provider.example.test', $raw);
        $this->assertStringNotContainsString('private-user', $encoded);
        $this->assertStringNotContainsString('private-password', $encoded);
        $this->assertStringNotContainsString('provider.example.test', $encoded);
        $this->assertSame('private-password', $source->settings['password']);
    }

    public function test_provider_response_size_is_bounded_before_catalog_parsing(): void
    {
        config()->set('mediahub_providers.max_response_bytes', 1024);
        Http::fake([
            'https://provider.example.test/*' => Http::response(str_repeat('x', 1025), 200),
        ]);

        $response = $this->actingAs($this->member())->postJson('/api/v1/providers/test', [
            'provider_type' => 'm3u',
            'playlist_url' => 'https://provider.example.test/catalog.m3u',
        ]);

        $response->assertOk()->assertExactJson([
            'reachable' => false,
            'authenticated' => false,
            'catalogAvailable' => false,
            'epgAvailable' => false,
            'errorCode' => 'provider_response_too_large',
        ]);
    }

    public function test_provider_test_returns_safe_status_only(): void
    {
        $user = $this->member();
        Http::fake(['provider.example.test/*' => Http::response(['user_info' => ['auth' => 1], 'server_info' => ['timezone' => 'UTC']])]);

        $response = $this->actingAs($user)->postJson('/api/v1/providers/test', [
            'name' => 'Private Catalog',
            'provider_type' => 'xtream',
            'base_url' => 'https://provider.example.test',
            'username' => 'private-user',
            'password' => 'private-password',
        ])->assertOk()
            ->assertExactJson(['reachable' => true, 'authenticated' => true, 'catalogAvailable' => true, 'epgAvailable' => false, 'errorCode' => null]);

        foreach (['private-user', 'private-password', 'provider.example.test', 'base_url', 'password'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent());
        }
    }

    public function test_saved_xtream_provider_is_never_synced_until_catalog_refresh_runs(): void
    {
        $user = $this->member();

        $response = $this->actingAs($user)->postJson('/api/v1/providers', [
            'name' => 'Private Catalog',
            'provider_type' => 'xtream',
            'base_url' => 'https://provider.example.test',
            'username' => 'private-user',
            'password' => 'private-password',
            'legal_confirmed' => true,
        ])->assertCreated()
            ->assertJsonPath('provider.syncStatus', 'never_synced')
            ->assertJsonPath('provider.itemsCount', 0)
            ->assertJsonPath('provider.activeItemsCount', 0);

        $this->assertNull($response->json('provider.lastSyncedAt'));
        $this->actingAs($user)->getJson('/api/v1/providers')
            ->assertOk()
            ->assertJsonPath('providers.0.syncStatus', 'never_synced');
    }

    public function test_failed_catalog_refresh_records_visible_safe_failure_state(): void
    {
        $user = $this->member();
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'Private Catalog',
            'provider_type' => 'xtream',
            'status' => 'active',
            'sync_status' => 'never_synced',
            'settings' => ['base_url' => 'https://provider.example.test', 'username' => 'private-user', 'password' => 'private-password'],
        ]);
        Http::fake(['provider.example.test/*' => Http::response('upstream error', 520)]);

        $this->actingAs($user)->postJson("/api/v1/providers/{$source->id}/refresh")
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Provider refresh failed.', 'errorCode' => 'provider_http_520']);

        $source->refresh();
        $this->assertSame('failed', $source->sync_status);
        $this->assertSame('provider_http_520', $source->last_sync_error);
        $this->assertNotNull($source->last_synced_at);
        $this->assertSame(1, $source->metadata['sync_summary']['failedEndpoints']);
        $this->assertSame('provider_http_520', $source->metadata['sync_summary']['safeFailureReason']);

        $payload = $this->actingAs($user)->getJson('/api/v1/providers')->assertOk();
        $payload->assertJsonPath('providers.0.syncStatus', 'failed')
            ->assertJsonPath('providers.0.lastSyncError', 'provider_http_520');
        foreach (['private-user', 'private-password', 'provider.example.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $payload->getContent());
        }
    }

    public function test_catalog_refresh_creates_scoped_items_and_list_payload_hides_raw_urls(): void
    {
        $user = $this->member();
        $other = $this->member('other@example.test');
        $source = PlaybackSource::create([
            'user_id' => $user->id, 'name' => 'Catalog', 'provider_type' => 'xtream', 'status' => 'active',
            'settings' => ['base_url' => 'https://provider.example.test', 'username' => 'private-user', 'password' => 'private-password'],
        ]);
        Http::fake(['provider.example.test/*' => Http::sequence()
            ->push(['user_info' => ['auth' => 1]])
            ->push([['category_id' => '1', 'category_name' => 'News']])
            ->push([['category_id' => '2', 'category_name' => 'Movies']])
            ->push([])
            ->push([['stream_id' => 10, 'name' => 'News Live', 'category_id' => '1', 'stream_icon' => 'https://provider.example.test/icon.png', 'epg_channel_id' => 'news']])
            ->push([['stream_id' => 20, 'name' => 'Heat', 'category_id' => '2', 'container_extension' => 'mp4', 'year' => 1995, 'tmdb_id' => 949]])
            ->push([]),
        ]);
        Movie::create(['user_id' => $user->id, 'title' => 'Heat', 'tmdb_id' => 949]);

        $this->actingAs($user)->postJson("/api/v1/providers/{$source->id}/refresh")
            ->assertOk()
            ->assertJsonPath('summary.created', 2)
            ->assertJsonPath('summary.suggested', 1)
            ->assertJsonPath('summary.liveItems', 1)
            ->assertJsonPath('summary.movieItems', 1)
            ->assertJsonPath('summary.seriesItems', 0)
            ->assertJsonPath('summary.episodeItems', 0)
            ->assertJsonPath('summary.categories', 2)
            ->assertJsonPath('summary.failedEndpoints', 0)
            ->assertJsonPath('provider.syncStatus', 'completed');

        $this->assertSame(2, PlaybackSourceItem::forUser($user)->count());
        $this->assertSame(0, PlaybackSourceItem::forUser($other)->count());
        $movieItem = PlaybackSourceItem::forUser($user)->where('kind', 'movie')->firstOrFail();
        $raw = (string) DB::table('playback_source_items')->where('id', $movieItem->id)->value('stream_url');
        $this->assertStringNotContainsString('private-user', $raw);
        $this->assertStringNotContainsString('private-password', $raw);
        $this->assertSame('suggested', $movieItem->match_status);

        $response = $this->actingAs($user)->getJson('/api/v1/player/catalog?view=movies')
            ->assertOk()
            ->assertJsonPath('counts.movies', 1)
            ->assertJsonPath('counts.live', 1)
            ->assertJsonPath('counts.categories', 2);
        foreach (['stream_url', 'playbackUrl', 'private-user', 'private-password', 'provider.example.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent());
        }
        $this->actingAs($other)->postJson("/api/v1/providers/{$source->id}/refresh")->assertNotFound();
    }

    public function test_xtream_series_and_episode_types_match_player_catalog_queries(): void
    {
        Config::set('mediahub_providers.series_detail_limit', 1);
        $user = $this->member();
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'Private Catalog',
            'provider_type' => 'xtream',
            'status' => 'active',
            'settings' => ['base_url' => 'https://provider.example.test', 'username' => 'private-user', 'password' => 'private-password'],
        ]);
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ($query['action'] ?? null) {
                null => Http::response(['user_info' => ['auth' => 1]]),
                'get_series_categories' => Http::response([['category_id' => '3', 'category_name' => 'Drama']]),
                'get_series' => Http::response([['series_id' => 30, 'name' => 'Example Show', 'category_id' => '3']]),
                'get_series_info' => Http::response(['episodes' => ['1' => [[
                    'id' => 31,
                    'title' => 'Example Episode',
                    'season' => 1,
                    'episode_num' => 1,
                    'container_extension' => 'mp4',
                ]]]]),
                default => Http::response([]),
            };
        });

        $this->actingAs($user)->postJson("/api/v1/providers/{$source->id}/refresh")
            ->assertOk()
            ->assertJsonPath('summary.seriesItems', 1)
            ->assertJsonPath('summary.episodeItems', 1);

        $this->actingAs($user)->getJson('/api/v1/player/catalog?view=shows')
            ->assertOk()
            ->assertJsonPath('counts.shows', 1)
            ->assertJsonPath('counts.episodes', 1)
            ->assertJsonCount(2, 'items');
    }

    public function test_catalog_refresh_updates_seen_items_and_deactivates_removed_items_without_deleting_links(): void
    {
        $user = $this->member();
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'Catalog',
            'provider_type' => 'xtream',
            'status' => 'active',
            'settings' => ['base_url' => 'https://provider.example.test', 'username' => 'private-user', 'password' => 'private-password'],
        ]);
        $refresh = 0;
        Http::fake(function ($request) use (&$refresh) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $action = $query['action'] ?? null;
            if ($action === null) {
                $refresh++;

                return Http::response(['user_info' => ['auth' => 1]]);
            }

            return match ($action) {
                'get_vod_categories' => Http::response([['category_id' => '2', 'category_name' => 'Movies']]),
                'get_vod_streams' => Http::response($refresh === 1
                    ? [
                        ['stream_id' => 20, 'name' => 'Heat', 'category_id' => '2', 'container_extension' => 'mp4'],
                        ['stream_id' => 21, 'name' => 'Arrival', 'category_id' => '2', 'container_extension' => 'mp4'],
                    ]
                    : [['stream_id' => 20, 'name' => 'Heat Updated', 'category_id' => '2', 'container_extension' => 'mp4']]),
                default => Http::response([]),
            };
        });

        $this->actingAs($user)->postJson("/api/v1/providers/{$source->id}/refresh")
            ->assertOk()
            ->assertJsonPath('summary.created', 2);

        $removedItem = PlaybackSourceItem::forUser($user)->where('external_id', 'movie:21')->firstOrFail();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Arrival']);
        $link = MediaLink::create([
            'user_id' => $user->id,
            'playback_source_item_id' => $removedItem->id,
            'movie_id' => $movie->id,
            'linked_at' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/v1/providers/{$source->id}/refresh")
            ->assertOk()
            ->assertJsonPath('summary.updated', 1)
            ->assertJsonPath('summary.deactivated', 1);

        $this->assertDatabaseHas('playback_source_items', ['id' => $removedItem->id, 'status' => 'unavailable']);
        $this->assertDatabaseHas('media_links', ['id' => $link->id, 'movie_id' => $movie->id]);
        $this->assertDatabaseHas('playback_source_items', ['playback_source_id' => $source->id, 'external_id' => 'movie:20', 'title' => 'Heat Updated']);
    }

    public function test_catalog_and_favorite_actions_are_scoped_to_the_authenticated_user(): void
    {
        $user = $this->member();
        $other = $this->member('other@example.test');
        $ownSource = PlaybackSource::create(['user_id' => $user->id, 'name' => 'Own', 'provider_type' => 'manual', 'status' => 'active']);
        $otherSource = PlaybackSource::create(['user_id' => $other->id, 'name' => 'Other', 'provider_type' => 'manual', 'status' => 'active']);
        PlaybackSourceItem::create(['user_id' => $user->id, 'playback_source_id' => $ownSource->id, 'external_id' => 'own', 'kind' => 'movie', 'title' => 'Own Item', 'status' => 'available']);
        $otherItem = PlaybackSourceItem::create(['user_id' => $other->id, 'playback_source_id' => $otherSource->id, 'external_id' => 'other', 'kind' => 'live', 'title' => 'Other Item', 'status' => 'available']);

        $response = $this->actingAs($user)->getJson('/api/v1/player/catalog?view=home')->assertOk();
        $this->assertStringContainsString('Own Item', $response->getContent());
        $this->assertStringNotContainsString('Other Item', $response->getContent());
        $this->actingAs($user)->patchJson("/api/v1/player/items/{$otherItem->id}/favorite", ['favorite' => true])->assertNotFound();
        $this->assertFalse($otherItem->fresh()->favorite);
    }

    public function test_live_playback_session_never_creates_movie_or_episode_watch_history(): void
    {
        $user = $this->member();
        $source = PlaybackSource::create(['user_id' => $user->id, 'name' => 'Live', 'provider_type' => 'manual', 'status' => 'active']);
        $item = PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => 'live:1',
            'kind' => 'live',
            'title' => 'Live Channel',
            'status' => 'available',
            'stream_url' => 'https://media.example.test/live.m3u8',
        ]);

        $sessionId = $this->actingAs($user)->postJson("/api/v1/player/items/{$item->id}/play")
            ->assertCreated()
            ->json('session.id');
        $this->actingAs($user)->patchJson("/api/v1/player/sessions/{$sessionId}", [
            'position_seconds' => 1800,
            'duration_seconds' => 1800,
            'completed' => true,
        ])->assertOk();

        $this->assertSame(0, MovieWatch::forUser($user)->count());
        $this->assertSame(0, EpisodeWatch::forUser($user)->count());
    }

    public function test_deleting_provider_preserves_canonical_history_ratings_and_notes(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);
        $watch = MovieWatch::create(['user_id' => $user->id, 'movie_id' => $movie->id, 'watched_at' => now(), 'source' => 'provider']);
        $rating = Rating::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'rating' => 10]);
        $note = Note::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'body' => 'Private memory']);
        $source = PlaybackSource::create(['user_id' => $user->id, 'name' => 'Manual', 'provider_type' => 'manual', 'status' => 'active']);
        $item = PlaybackSourceItem::create(['user_id' => $user->id, 'playback_source_id' => $source->id, 'external_id' => 'heat', 'kind' => 'movie', 'title' => 'Heat', 'stream_url' => 'https://media.example.test/heat.m3u8']);
        MediaLink::create(['user_id' => $user->id, 'playback_source_item_id' => $item->id, 'movie_id' => $movie->id, 'linked_at' => now()]);

        $this->actingAs($user)->deleteJson("/api/v1/providers/{$source->id}")->assertNoContent();

        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertDatabaseHas('movie_watches', ['id' => $watch->id]);
        $this->assertDatabaseHas('ratings', ['id' => $rating->id]);
        $this->assertDatabaseHas('notes', ['id' => $note->id]);
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'role' => UserRole::Member, 'status' => UserStatus::Active]);
    }

    /** @return array<string, mixed> */
    private function movieDetails(): array
    {
        return ['id' => 949, 'title' => 'Heat', 'original_title' => 'Heat', 'overview' => 'Crime saga.', 'poster_path' => '/heat.jpg', 'backdrop_path' => '/heat-bg.jpg', 'release_date' => '1995-12-15', 'genres' => [['id' => 80, 'name' => 'Crime']], 'runtime' => 170, 'status' => 'Released', 'vote_average' => 8.0, 'imdb_id' => 'tt0113277'];
    }

    /** @return array<string, mixed> */
    private function showDetails(): array
    {
        return ['id' => 95396, 'name' => 'Severance', 'original_name' => 'Severance', 'overview' => 'Work-life separation.', 'poster_path' => '/show.jpg', 'backdrop_path' => '/show-bg.jpg', 'first_air_date' => '2022-02-18', 'genres' => [['id' => 18, 'name' => 'Drama']], 'episode_run_time' => [50], 'status' => 'Returning Series', 'vote_average' => 8.4, 'external_ids' => ['imdb_id' => 'tt11280740', 'tvdb_id' => 371980]];
    }
}
