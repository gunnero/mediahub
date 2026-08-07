<?php

namespace Tests\Feature;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaEvent;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use App\Services\CanonicalWatchHistoryService;
use App\Services\EpisodeCatalogService;
use App\Services\LibraryBrowserService;
use App\Services\MediaDataHealthService;
use App\Services\MediaDiaryBackfillService;
use App\Services\MediaEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaDataQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_episode_catalog_sync_backfills_canonical_episode_dates_and_progress(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $show = Show::create(['user_id' => $user->id, 'title' => 'Canonical Show', 'tmdb_id' => 100]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'external_source' => 'archive',
            'external_id' => 'episode-one',
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Local title',
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episode->id,
            'watched_at' => now()->subDay(),
            'source' => 'import',
        ]);
        $privateShow = Show::create(['user_id' => $other->id, 'title' => 'Private Show', 'tmdb_id' => 200]);

        Http::fake([
            'api.themoviedb.org/3/tv/100/season/1*' => Http::response([
                'episodes' => [
                    ['id' => 1001, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Pilot', 'air_date' => now()->subMonth()->toDateString(), 'runtime' => 45, 'still_path' => '/one.jpg'],
                    ['id' => 1002, 'season_number' => 1, 'episode_number' => 2, 'name' => 'Next', 'air_date' => now()->subDay()->toDateString(), 'runtime' => 47, 'still_path' => '/two.jpg'],
                ],
            ]),
            'api.themoviedb.org/3/tv/100*' => Http::response([
                'id' => 100,
                'seasons' => [['season_number' => 1, 'episode_count' => 2]],
            ]),
        ]);

        $summary = app(EpisodeCatalogService::class)->syncUser($user);

        $this->assertSame(1, $summary['shows_synced']);
        $this->assertSame(1, $summary['episodes_created']);
        $this->assertSame(1, $summary['episodes_updated']);
        $this->assertSame(1001, $episode->refresh()->tmdb_id);
        $this->assertSame('Local title', $episode->title);
        $this->assertSame('Pilot', $episode->original_title);
        $this->assertSame(2, Episode::forUser($user)->where('show_id', $show->id)->count());
        $this->assertSame(1, $show->refresh()->seen_episodes);
        $this->assertSame(2, $show->aired_episodes);
        $this->assertSame(0, Episode::forUser($other)->where('show_id', $privateShow->id)->count());
    }

    public function test_episode_catalog_command_can_refresh_every_user(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstShow = Show::create(['user_id' => $firstUser->id, 'title' => 'First Show', 'tmdb_id' => 101]);
        $secondShow = Show::create(['user_id' => $secondUser->id, 'title' => 'Second Show', 'tmdb_id' => 202]);

        Http::fake([
            'api.themoviedb.org/3/tv/101/season/1*' => Http::response(['episodes' => [
                ['id' => 10101, 'season_number' => 1, 'episode_number' => 1, 'name' => 'First episode'],
            ]]),
            'api.themoviedb.org/3/tv/101*' => Http::response(['id' => 101, 'seasons' => [['season_number' => 1, 'episode_count' => 1]]]),
            'api.themoviedb.org/3/tv/202/season/1*' => Http::response(['episodes' => [
                ['id' => 20201, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Second episode'],
            ]]),
            'api.themoviedb.org/3/tv/202*' => Http::response(['id' => 202, 'seasons' => [['season_number' => 1, 'episode_count' => 1]]]),
        ]);

        $this->artisan('mediahub:sync-episode-catalog', ['--all' => true, '--sleep-ms' => 0])
            ->expectsOutput('users_synced: 2')
            ->assertSuccessful();

        $this->assertSame(1, Episode::forUser($firstUser)->where('show_id', $firstShow->id)->count());
        $this->assertSame(1, Episode::forUser($secondUser)->where('show_id', $secondShow->id)->count());
    }

    public function test_manual_movie_match_refreshes_canonical_metadata_without_replacing_imported_title(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $movie = Movie::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Imported display title',
        ]);
        Http::fake([
            'api.themoviedb.org/3/movie/438631*' => Http::response([
                'id' => 438631,
                'title' => 'Dune',
                'original_title' => 'Dune',
                'release_date' => '2021-09-15',
                'poster_path' => '/dune.jpg',
                'backdrop_path' => '/dune-backdrop.jpg',
                'genres' => [['id' => 878, 'name' => 'Science Fiction']],
                'runtime' => 155,
            ]),
        ]);

        $this->artisan('mediahub:match-movie', ['movie_id' => $movie->id, '--tmdb-id' => 438631])
            ->assertSuccessful();

        $movie->refresh();
        $this->assertSame('Imported display title', $movie->title);
        $this->assertSame('Dune', $movie->original_title);
        $this->assertSame(438631, $movie->tmdb_id);
        $this->assertSame('/dune.jpg', $movie->poster_path);
        $this->assertSame('manual', $movie->metadata['match']['method']);
    }

    public function test_diary_backfill_is_idempotent_preserves_rewatches_and_excludes_private_note_body(): void
    {
        $user = User::factory()->create();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'A Movie']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'A Show']);
        $episode = Episode::create(['user_id' => $user->id, 'show_id' => $show->id, 'season_number' => 1, 'episode_number' => 1, 'title' => 'An Episode']);
        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now()->subDays(3),
            'watch_count' => 2,
            'runtime' => 100,
            'source' => 'import',
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episode->id,
            'watched_at' => now()->subDays(2),
            'runtime' => 45,
            'source' => 'import',
        ]);
        Rating::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'rating' => 9]);
        Note::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'body' => 'Never put this private note in the diary.']);

        $first = app(MediaDiaryBackfillService::class)->backfill($user);
        $second = app(MediaDiaryBackfillService::class)->backfill($user);

        $this->assertSame(8, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(8, $second['existing']);
        $this->assertSame(2, MediaEvent::forUser($user)->where('event_type', MediaEventType::MovieWatched->value)->count());
        $this->assertSame(1, MediaEvent::forUser($user)->where('event_type', MediaEventType::EpisodeWatched->value)->count());
        $this->assertStringNotContainsString('Never put this', MediaEvent::forUser($user)->get()->toJson());

        app(MediaEventService::class)->record($user, MediaEventType::MetadataEnriched, $movie, ['title' => 'A Movie'], MediaEventSource::Metadata);
        $timeline = app(MediaEventService::class)->dashboardTimeline($user);
        $this->assertNotContains(MediaEventType::MetadataEnriched->value, collect($timeline['recent'])->pluck('eventType')->all());

        $health = app(MediaDataHealthService::class)->analyze($user);
        $this->assertTrue($health['canonical_consistency']['statistics_match_canonical']);
        $this->assertTrue($health['canonical_consistency']['diary_matches_canonical']);
        $this->assertTrue($health['canonical_consistency']['calendar_matches_canonical']);
        $this->assertTrue($health['canonical_consistency']['alerts_match_canonical']);
        $this->assertSame(3, $health['canonical_consistency']['total_watches']);
        $this->assertStringNotContainsString('A Movie', app(MediaDataHealthService::class)->markdown($health));
    }

    public function test_continue_watching_uses_deterministic_order_when_air_date_is_missing(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['user_id' => $user->id, 'title' => 'Undated Show']);
        $watched = Episode::create(['user_id' => $user->id, 'show_id' => $show->id, 'season_number' => 1, 'episode_number' => 1]);
        $next = Episode::create(['user_id' => $user->id, 'show_id' => $show->id, 'season_number' => 1, 'episode_number' => 2]);
        Episode::create(['user_id' => $user->id, 'show_id' => $show->id, 'season_number' => 1, 'episode_number' => 3, 'air_date' => now()->addDay()]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $watched->id,
            'watched_at' => now()->subHour(),
            'source' => 'manual',
        ]);

        app(CanonicalWatchHistoryService::class)->recalculateShow($user, $show);
        $items = app(LibraryBrowserService::class)->continueWatching($user)['items'];

        $this->assertCount(1, $items);
        $this->assertSame($next->id, $items[0]['episodeId']);
    }

    public function test_health_report_counts_duplicates_and_broken_references_without_exposing_titles(): void
    {
        $user = User::factory()->create();
        Movie::create(['user_id' => $user->id, 'title' => 'Duplicate Secret', 'release_date' => '2020-01-01']);
        Movie::create(['user_id' => $user->id, 'title' => 'Duplicate Secret', 'release_date' => '2020-06-01']);
        MovieWatch::create(['user_id' => $user->id, 'movie_id' => null, 'watched_at' => now(), 'source' => 'import']);

        $health = app(MediaDataHealthService::class)->analyze($user);
        $markdown = app(MediaDataHealthService::class)->markdown($health);

        $this->assertSame(1, $health['duplicates']['movie_title_year']['groups']);
        $this->assertSame(2, $health['duplicates']['movie_title_year']['records']);
        $this->assertSame(1, $health['broken_references']['movie_watches_without_owned_movie']);
        $this->assertStringNotContainsString('Duplicate Secret', $markdown);
    }
}
