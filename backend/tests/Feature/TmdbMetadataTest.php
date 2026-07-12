<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaEvent;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use App\Services\DashboardPayloadService;
use App\Services\MediaMetadataService;
use App\Services\TMDBClientService;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TmdbMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_tmdb_uses_dedicated_cache_store_instead_of_sqlite_default(): void
    {
        Config::set('cache.default', 'database');
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        Config::set('tmdb.cache_store', 'file');
        Config::set('tmdb.cache_ttl', 86400);

        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('remember')
            ->once()
            ->with(
                Mockery::on(fn (string $key): bool => str_starts_with($key, 'tmdb:')
                    && ! str_contains($key, 'test-key')
                    && ! str_contains($key, 'Heat')),
                86400,
                Mockery::type(Closure::class),
            )
            ->andReturnUsing(fn (string $key, int $ttl, Closure $callback): mixed => $callback());
        Cache::shouldReceive('store')->once()->with('file')->andReturn($cache);

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([
                'results' => [['id' => 949, 'title' => 'Heat']],
            ]),
        ]);

        $result = app(TMDBClientService::class)->searchMovie('Heat');

        $this->assertSame(949, $result['results'][0]['id']);
    }

    public function test_tmdb_disabled_does_not_enrich_movie_or_fail(): void
    {
        Config::set('tmdb.enabled', false);

        $movie = Movie::create([
            'user_id' => $this->member()->id,
            'title' => 'Heat',
            'runtime' => 170,
        ]);

        $summary = app(MediaMetadataService::class)->enrichMovie($movie);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $summary['enriched']);
        $this->assertNull($movie->refresh()->tmdb_id);
    }

    public function test_failed_tmdb_request_does_not_break_app_or_log_api_key(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'super-secret-tmdb-key');
        Log::spy();
        Http::fake([
            'api.themoviedb.org/*' => Http::response(['status_message' => 'bad gateway'], 502),
        ]);

        $movie = Movie::create([
            'user_id' => $this->member()->id,
            'title' => 'Heat',
        ]);

        $summary = app(MediaMetadataService::class)->enrichMovie($movie);

        $this->assertSame(1, $summary['failed']);
        $this->assertNull($movie->refresh()->tmdb_id);
        Log::shouldNotHaveReceived('warning', function (string $message, array $context = []): bool {
            return str_contains(json_encode([$message, $context], JSON_THROW_ON_ERROR), 'super-secret-tmdb-key');
        });
    }

    public function test_movie_enrichment_stores_canonical_metadata_additively(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Heat',
            'runtime' => 0,
            'poster_url' => '/assets/generated/movie-poster-1.png',
        ]);

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([
                'results' => [[
                    'id' => 949,
                    'title' => 'Heat',
                    'original_title' => 'Heat',
                    'release_date' => '1995-12-15',
                    'overview' => 'A meticulous crime saga.',
                    'poster_path' => '/heat-poster.jpg',
                    'backdrop_path' => '/heat-backdrop.jpg',
                    'vote_average' => 7.9,
                ]],
            ]),
            'api.themoviedb.org/3/movie/949*' => Http::response([
                'id' => 949,
                'imdb_id' => 'tt0113277',
                'title' => 'Heat',
                'original_title' => 'Heat',
                'overview' => 'A meticulous crime saga.',
                'poster_path' => '/heat-poster.jpg',
                'backdrop_path' => '/heat-backdrop.jpg',
                'release_date' => '1995-12-15',
                'genres' => [['id' => 80, 'name' => 'Crime'], ['id' => 18, 'name' => 'Drama']],
                'runtime' => 170,
                'status' => 'Released',
                'vote_average' => 7.9,
            ]),
        ]);

        $summary = app(MediaMetadataService::class)->enrichMovie($movie);
        $movie->refresh();

        $this->assertSame(1, $summary['matched']);
        $this->assertSame(1, $summary['enriched']);
        $this->assertSame(949, $movie->tmdb_id);
        $this->assertSame('tt0113277', $movie->imdb_id);
        $this->assertSame('Heat', $movie->original_title);
        $this->assertSame('A meticulous crime saga.', $movie->overview);
        $this->assertSame('/heat-poster.jpg', $movie->poster_path);
        $this->assertSame('/heat-backdrop.jpg', $movie->backdrop_path);
        $this->assertSame('1995-12-15', $movie->release_date?->toDateString());
        $this->assertSame('Crime', $movie->genres[0]['name']);
        $this->assertSame(170, $movie->runtime);
        $this->assertSame('Released', $movie->status);
        $this->assertSame(7.9, $movie->vote_average);
        $this->assertSame('/assets/generated/movie-poster-1.png', $movie->poster_url);
        $this->assertSame('tmdb', $movie->metadata['match']['source']);
        $this->assertNotNull($movie->metadata_refreshed_at);
    }

    public function test_show_and_episode_enrichment_store_metadata_when_show_has_tmdb_id(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Severance',
            'runtime' => 0,
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Good News About Hell',
        ]);

        Http::fake([
            'api.themoviedb.org/3/search/tv*' => Http::response([
                'results' => [[
                    'id' => 95396,
                    'name' => 'Severance',
                    'original_name' => 'Severance',
                    'first_air_date' => '2022-02-18',
                    'overview' => 'Work-life balance, surgically enforced.',
                    'poster_path' => '/severance-poster.jpg',
                    'backdrop_path' => '/severance-backdrop.jpg',
                    'vote_average' => 8.4,
                ]],
            ]),
            'api.themoviedb.org/3/tv/95396/season/1/episode/1*' => Http::response([
                'id' => 1832406,
                'name' => 'Good News About Hell',
                'overview' => 'Mark reports to work.',
                'still_path' => '/severance-episode.jpg',
                'air_date' => '2022-02-18',
                'runtime' => 57,
                'vote_average' => 8.1,
                'external_ids' => ['imdb_id' => 'tt15047476', 'tvdb_id' => 8304110],
            ]),
            'api.themoviedb.org/3/tv/95396*' => Http::response([
                'id' => 95396,
                'name' => 'Severance',
                'original_name' => 'Severance',
                'overview' => 'Work-life balance, surgically enforced.',
                'poster_path' => '/severance-poster.jpg',
                'backdrop_path' => '/severance-backdrop.jpg',
                'first_air_date' => '2022-02-18',
                'genres' => [['id' => 18, 'name' => 'Drama']],
                'episode_run_time' => [50],
                'status' => 'Returning Series',
                'vote_average' => 8.4,
                'external_ids' => ['imdb_id' => 'tt11280740', 'tvdb_id' => 371980],
                'next_episode_to_air' => [
                    'id' => 2000001,
                    'season_number' => 3,
                    'episode_number' => 1,
                    'name' => 'The Return',
                    'air_date' => '2027-01-10',
                    'overview' => 'A safe public synopsis.',
                    'runtime' => 52,
                    'still_path' => '/next.jpg',
                ],
            ]),
        ]);

        $summary = app(MediaMetadataService::class)->enrichShow($show, enrichEpisodes: true);

        $this->assertSame(2, $summary['enriched']);
        $this->assertSame(95396, $show->refresh()->tmdb_id);
        $this->assertSame('tt11280740', $show->imdb_id);
        $this->assertSame('371980', $show->tvdb_id);
        $this->assertSame('/severance-poster.jpg', $show->poster_path);
        $this->assertSame('2022-02-18', $show->first_air_date?->toDateString());
        $this->assertSame(50, $show->runtime);
        $this->assertSame('2027-01-10', $show->metadata['release']['next_episode']['air_date']);
        $this->assertSame(3, $show->metadata['release']['next_episode']['season_number']);
        $this->assertSame(1832406, $episode->refresh()->tmdb_id);
        $this->assertSame('tt15047476', $episode->imdb_id);
        $this->assertSame('8304110', $episode->tvdb_id);
        $this->assertSame('/severance-episode.jpg', $episode->poster_path);
        $this->assertSame(57, $episode->runtime);
    }

    public function test_user_enrichment_is_scoped_and_dashboard_hides_provider_urls(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $otherUser = $this->member('other@example.test');
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);
        $otherMovie = Movie::create(['user_id' => $otherUser->id, 'title' => 'Heat']);
        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now(),
            'runtime' => 170,
            'source' => 'manual',
        ]);
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => 'Private source',
            'provider_type' => 'manual',
            'status' => 'active',
        ]);
        $item = PlaybackSourceItem::create([
            'user_id' => $user->id,
            'playback_source_id' => $source->id,
            'external_id' => 'private-heat',
            'kind' => 'movie',
            'title' => 'Heat private',
            'status' => 'available',
            'stream_url' => 'mediahub-private-stream-ref',
            'stream_url_hash' => hash('sha256', 'mediahub-private-stream-ref'),
        ]);
        MediaLink::create([
            'user_id' => $user->id,
            'playback_source_item_id' => $item->id,
            'movie_id' => $movie->id,
            'linked_at' => now(),
        ]);

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response(['results' => [['id' => 949, 'title' => 'Heat']]]),
            'api.themoviedb.org/3/movie/949*' => Http::response([
                'id' => 949,
                'title' => 'Heat',
                'poster_path' => '/heat-poster.jpg',
                'backdrop_path' => '/heat-backdrop.jpg',
                'release_date' => '1995-12-15',
                'genres' => [['id' => 80, 'name' => 'Crime']],
                'runtime' => 170,
                'status' => 'Released',
            ]),
        ]);

        $summary = app(MediaMetadataService::class)->enrichUser($user);

        $this->assertSame(1, $summary['enriched']);
        $this->assertSame(949, $movie->refresh()->tmdb_id);
        $this->assertNull($otherMovie->refresh()->tmdb_id);

        $payload = app(DashboardPayloadService::class)->forUser($user);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('https://image.tmdb.org/t/p/w500/heat-poster.jpg', $payload['recentlyWatched'][0]['poster']);
        $this->assertStringContainsString('Crime', $encoded);
        $this->assertStringNotContainsString('stream_url', $encoded);
        $this->assertStringNotContainsString('playbackUrl', $encoded);
        $this->assertStringNotContainsString('mediahub-private-stream-ref', $encoded);
    }

    public function test_user_enrichment_dry_run_respects_limit_and_does_not_write(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $firstMovie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);
        $secondMovie = Movie::create(['user_id' => $user->id, 'title' => 'Arrival']);
        Http::preventStrayRequests();

        $this->artisan('mediahub:enrich-user', [
            'user_id' => $user->id,
            '--type' => 'movies',
            '--only-missing' => true,
            '--limit' => 1,
            '--dry-run' => true,
        ])
            ->expectsOutput('planned: 1')
            ->expectsOutput('enriched: 0')
            ->assertExitCode(0);

        $this->assertNull($firstMovie->refresh()->tmdb_id);
        $this->assertNull($secondMovie->refresh()->tmdb_id);
        Http::assertNothingSent();
    }

    public function test_user_enrichment_limit_and_only_missing_are_respected(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $alreadyEnriched = Movie::create([
            'user_id' => $user->id,
            'title' => 'Already Enriched',
            'tmdb_id' => 1,
            'metadata_refreshed_at' => now(),
        ]);
        $firstMissing = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);
        $secondMissing = Movie::create(['user_id' => $user->id, 'title' => 'Arrival']);

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([
                'results' => [[
                    'id' => 949,
                    'title' => 'Heat',
                    'release_date' => '1995-12-15',
                ]],
            ]),
            'api.themoviedb.org/3/movie/949*' => Http::response([
                'id' => 949,
                'title' => 'Heat',
                'poster_path' => '/heat-poster.jpg',
                'backdrop_path' => '/heat-backdrop.jpg',
                'release_date' => '1995-12-15',
                'genres' => [['id' => 80, 'name' => 'Crime']],
                'runtime' => 170,
                'status' => 'Released',
            ]),
        ]);

        $this->artisan('mediahub:enrich-user', [
            'user_id' => $user->id,
            '--type' => 'movies',
            '--only-missing' => true,
            '--limit' => 1,
        ])
            ->expectsOutput('planned: 1')
            ->expectsOutput('enriched: 1')
            ->assertExitCode(0);

        $this->assertSame(1, $alreadyEnriched->refresh()->tmdb_id);
        $this->assertSame(949, $firstMissing->refresh()->tmdb_id);
        $this->assertNull($secondMissing->refresh()->tmdb_id);
    }

    public function test_user_enrichment_min_confidence_skips_weak_matches_safely(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([
                'results' => [[
                    'id' => 123,
                    'title' => 'A Completely Different Film',
                    'release_date' => '2020-01-01',
                ]],
            ]),
            'api.themoviedb.org/3/movie/123*' => Http::response([
                'id' => 123,
                'title' => 'A Completely Different Film',
                'poster_path' => '/wrong-poster.jpg',
            ]),
        ]);

        $this->artisan('mediahub:enrich-user', [
            'user_id' => $user->id,
            '--type' => 'movies',
            '--only-missing' => true,
            '--limit' => 1,
            '--min-confidence' => 0.8,
        ])
            ->expectsOutput('planned: 1')
            ->expectsOutput('enriched: 0')
            ->expectsOutput('skipped: 1')
            ->assertExitCode(0);

        $this->assertNull($movie->refresh()->tmdb_id);
        Http::assertSentCount(1);
    }

    public function test_episode_enrichment_can_skip_episodes_without_parent_tmdb_before_calling_tmdb(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $matchedShow = Show::create([
            'user_id' => $user->id,
            'title' => 'Matched Show',
            'tmdb_id' => 123,
            'metadata_refreshed_at' => now(),
        ]);
        $unmatchedShow = Show::create([
            'user_id' => $user->id,
            'title' => 'Unmatched Show',
        ]);
        $eligibleEpisode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $matchedShow->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Pilot',
        ]);
        $seasonZeroEpisode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $matchedShow->id,
            'season_number' => 0,
            'episode_number' => 1,
            'title' => 'Special',
        ]);
        $episodeZeroEpisode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $matchedShow->id,
            'season_number' => 1,
            'episode_number' => 0,
            'title' => 'Preview',
        ]);
        $blockedEpisode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $unmatchedShow->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Blocked',
        ]);

        Http::fake([
            'api.themoviedb.org/3/tv/123/season/1/episode/1*' => Http::response([
                'id' => 456,
                'name' => 'Pilot',
                'overview' => 'A matched episode.',
                'runtime' => 42,
                'external_ids' => [],
            ]),
        ]);

        $this->artisan('mediahub:enrich-user', [
            'user_id' => $user->id,
            '--type' => 'episodes',
            '--only-missing' => true,
            '--only-parent-enriched' => true,
            '--limit' => 100,
        ])
            ->expectsOutput('planned: 1')
            ->expectsOutput('enriched: 1')
            ->expectsOutput('skipped: 0')
            ->assertExitCode(0);

        $this->assertSame(456, $eligibleEpisode->refresh()->tmdb_id);
        $this->assertNull($blockedEpisode->refresh()->tmdb_id);
        $this->assertNull($seasonZeroEpisode->refresh()->tmdb_id);
        $this->assertNull($episodeZeroEpisode->refresh()->tmdb_id);
        Http::assertSentCount(1);
    }

    public function test_guarded_episode_enrichment_limit_does_not_waste_slots_on_invalid_numbering(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Matched Show',
            'tmdb_id' => 123,
            'metadata_refreshed_at' => now(),
        ]);
        $seasonZeroEpisode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 0,
            'episode_number' => 1,
            'title' => 'Special',
        ]);
        $episodeZeroEpisode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 0,
            'title' => 'Preview',
        ]);
        $eligibleEpisode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Pilot',
        ]);

        Http::fake([
            'api.themoviedb.org/3/tv/123/season/1/episode/1*' => Http::response([
                'id' => 456,
                'name' => 'Pilot',
                'overview' => 'A matched episode.',
                'runtime' => 42,
                'external_ids' => [],
            ]),
        ]);

        $this->artisan('mediahub:enrich-user', [
            'user_id' => $user->id,
            '--type' => 'episodes',
            '--only-missing' => true,
            '--only-parent-enriched' => true,
            '--limit' => 1,
        ])
            ->expectsOutput('planned: 1')
            ->expectsOutput('enriched: 1')
            ->expectsOutput('skipped: 0')
            ->assertExitCode(0);

        $this->assertSame(456, $eligibleEpisode->refresh()->tmdb_id);
        $this->assertNull($seasonZeroEpisode->refresh()->tmdb_id);
        $this->assertNull($episodeZeroEpisode->refresh()->tmdb_id);
        Http::assertSentCount(1);
    }

    public function test_metadata_status_includes_episode_blocked_and_eligible_breakdown(): void
    {
        $user = $this->member();
        $matchedShow = Show::create([
            'user_id' => $user->id,
            'title' => 'Matched Show',
            'tmdb_id' => 123,
            'metadata_refreshed_at' => now(),
        ]);
        $unmatchedShow = Show::create([
            'user_id' => $user->id,
            'title' => 'Unmatched Show',
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $matchedShow->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Enriched',
            'tmdb_id' => 456,
            'metadata_refreshed_at' => now(),
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $matchedShow->id,
            'season_number' => 1,
            'episode_number' => 2,
            'title' => 'Eligible',
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $matchedShow->id,
            'season_number' => 0,
            'episode_number' => 1,
            'title' => 'Special',
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $matchedShow->id,
            'season_number' => 1,
            'episode_number' => 0,
            'title' => 'Preview',
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $unmatchedShow->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Blocked',
        ]);

        $this->artisan('mediahub:metadata-status', ['user_id' => $user->id])
            ->expectsOutput('episodes_total: 5')
            ->expectsOutput('episodes_enriched: 1')
            ->expectsOutput('episodes_missing_metadata: 4')
            ->expectsOutput('episodes_blocked_no_parent_tmdb: 1')
            ->expectsOutput('episodes_not_enrichable_invalid_numbering: 2')
            ->expectsOutput('episodes_eligible_for_enrichment: 1')
            ->assertExitCode(0);
    }

    public function test_unmatched_show_review_outputs_safe_summary_only(): void
    {
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Unmatched Show',
            'seen_episodes' => 2,
            'aired_episodes' => 10,
            'latest_seen_at' => '2026-07-01 12:00:00',
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => Episode::create([
                'user_id' => $user->id,
                'show_id' => $show->id,
                'season_number' => 1,
                'episode_number' => 1,
                'title' => 'Episode 1',
            ])->id,
            'watched_at' => '2026-07-02 12:00:00',
            'runtime' => 42,
            'source' => 'manual',
        ]);

        $this->artisan('mediahub:metadata-unmatched-shows', ['user_id' => $user->id])
            ->expectsOutput('unmatched_shows: 1')
            ->expectsOutput('show_id: '.$show->id.' | title: Unmatched Show | watched_episodes: 1 | aired_episodes: 10 | latest_watched_at: 2026-07-02 | match_status: unmatched')
            ->assertExitCode(0);
    }

    public function test_manual_show_match_stores_metadata_and_records_safe_event(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Manual Match',
        ]);
        $otherUser = $this->member('manual-other@example.test');
        $otherShow = Show::create([
            'user_id' => $otherUser->id,
            'title' => 'Manual Match',
        ]);

        Http::fake([
            'api.themoviedb.org/3/tv/95396*' => Http::response([
                'id' => 95396,
                'name' => 'Manual Match',
                'original_name' => 'Manual Match',
                'overview' => 'Manually selected metadata.',
                'poster_path' => '/manual-poster.jpg',
                'backdrop_path' => '/manual-backdrop.jpg',
                'first_air_date' => '2022-02-18',
                'genres' => [['id' => 18, 'name' => 'Drama']],
                'episode_run_time' => [50],
                'status' => 'Returning Series',
                'vote_average' => 8.4,
                'external_ids' => ['imdb_id' => 'tt11280740', 'tvdb_id' => 371980],
            ]),
        ]);

        $this->artisan('mediahub:match-show', [
            'show_id' => $show->id,
            '--tmdb-id' => 95396,
        ])
            ->expectsOutput('show_id: '.$show->id)
            ->expectsOutput('tmdb_id: 95396')
            ->expectsOutput('match_method: manual')
            ->assertExitCode(0);

        $show->refresh();
        $this->assertSame(95396, $show->tmdb_id);
        $this->assertSame('/manual-poster.jpg', $show->poster_path);
        $this->assertSame('manual', $show->metadata['match']['method']);
        $this->assertEquals(1.0, $show->metadata['match']['confidence']);
        $this->assertNull($otherShow->refresh()->tmdb_id);
        $event = MediaEvent::forUser($user)->where('subject_id', $show->id)->firstOrFail();
        $this->assertSame('metadata.enriched', $event->event_type);
        $this->assertSame('metadata', $event->source);
        $this->assertStringNotContainsString('stream_url', json_encode($event->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_repeated_episode_404_is_tracked_and_removed_from_bulk_eligible_queue(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Matched Show',
            'tmdb_id' => 123,
            'metadata_refreshed_at' => now(),
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 99,
            'title' => 'Missing Episode',
        ]);
        Http::fake([
            'api.themoviedb.org/3/tv/123/season/1/episode/99*' => Http::response(['status_message' => 'not found'], 404),
        ]);

        app(MediaMetadataService::class)->enrichEpisode($episode);
        app(MediaMetadataService::class)->enrichEpisode($episode->refresh());
        $summary = app(MediaMetadataService::class)->enrichEpisode($episode->refresh());

        $episode->refresh();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(3, $episode->metadata_failure_count);
        $this->assertSame('tmdb_404', $episode->last_metadata_failure_reason);
        $this->assertSame('pending', $episode->metadata_review_status);
        $this->assertNotNull($episode->metadata_failed_at);

        $this->artisan('mediahub:metadata-status', ['user_id' => $user->id])
            ->expectsOutput('episodes_eligible_for_enrichment: 0')
            ->assertExitCode(0);
    }

    public function test_ignored_episode_is_excluded_from_eligible_queue(): void
    {
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Matched Show',
            'tmdb_id' => 123,
            'metadata_refreshed_at' => now(),
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 2,
            'title' => 'Ignored Episode',
        ]);

        $this->artisan('mediahub:metadata-ignore-episode', ['episode_id' => $episode->id])
            ->expectsOutput('episode_id: '.$episode->id)
            ->expectsOutput('metadata_review_status: ignored')
            ->assertExitCode(0);

        $this->assertSame('ignored', $episode->refresh()->metadata_review_status);
        $this->artisan('mediahub:metadata-status', ['user_id' => $user->id])
            ->expectsOutput('episodes_eligible_for_enrichment: 0')
            ->assertExitCode(0);
    }

    public function test_manual_episode_match_stores_metadata_and_marks_review_status(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Matched Show',
            'tmdb_id' => 123,
            'metadata_refreshed_at' => now(),
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 99,
            'title' => 'Wrong Number',
            'metadata_failure_count' => 3,
            'last_metadata_failure_reason' => 'tmdb_404',
            'metadata_failed_at' => now(),
        ]);
        Http::fake([
            'api.themoviedb.org/3/tv/123/season/1/episode/9*' => Http::response([
                'id' => 999,
                'name' => 'Manual Episode',
                'overview' => 'Manually selected episode metadata.',
                'runtime' => 44,
                'external_ids' => [],
            ]),
        ]);

        $this->artisan('mediahub:match-episode', [
            'episode_id' => $episode->id,
            '--tmdb-season' => 1,
            '--tmdb-episode' => 9,
        ])
            ->expectsOutput('episode_id: '.$episode->id)
            ->expectsOutput('tmdb_id: 999')
            ->expectsOutput('match_method: manual')
            ->assertExitCode(0);

        $episode->refresh();
        $this->assertSame(999, $episode->tmdb_id);
        $this->assertSame('Manual Episode', $episode->original_title);
        $this->assertSame('manually_matched', $episode->metadata_review_status);
        $this->assertSame(0, $episode->metadata_failure_count);
        $this->assertNull($episode->last_metadata_failure_reason);
        $this->assertNull($episode->metadata_failed_at);
    }

    public function test_metadata_review_queue_outputs_safe_grouped_summary(): void
    {
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Queue Show',
            'tmdb_id' => 123,
            'metadata_refreshed_at' => now(),
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 2,
            'episode_number' => 14,
            'title' => 'Private title should not be printed',
            'metadata_failure_count' => 3,
            'last_metadata_failure_reason' => 'tmdb_404',
            'metadata_failed_at' => now(),
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 2,
            'episode_number' => 15,
            'title' => 'Another private title',
            'metadata_failure_count' => 3,
            'last_metadata_failure_reason' => 'tmdb_404',
            'metadata_failed_at' => now(),
        ]);

        $this->artisan('mediahub:metadata-review-queue', ['user_id' => $user->id])
            ->expectsOutput('review_groups: 1')
            ->expectsOutput('show_id: '.$show->id.' | show: Queue Show | season: 2 | reason: tmdb_404 | count: 2')
            ->doesntExpectOutput('Private title should not be printed')
            ->doesntExpectOutput('Another private title')
            ->assertExitCode(0);
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
