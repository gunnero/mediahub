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
use App\Services\LibraryBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LibraryBrowserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_movie_library_endpoint_searches_filters_sorts_and_returns_safe_cards(): void
    {
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Arrival',
            'runtime' => 116,
            'release_date' => '2016-11-11',
            'poster_path' => '/arrival.jpg',
            'metadata_refreshed_at' => now(),
        ]);
        Movie::create(['user_id' => $user->id, 'title' => 'Watchlist Only', 'is_to_watch' => true]);
        Movie::create(['user_id' => $this->member('other@example.test')->id, 'title' => 'Arrival Other']);
        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now()->subDay(),
            'runtime' => 116,
            'source' => 'manual',
        ]);
        Rating::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'rating' => 9]);
        Note::create(['user_id' => $user->id, 'media_type' => 'movie', 'media_id' => $movie->id, 'body' => 'Private.']);
        MediaLink::create([
            'user_id' => $user->id,
            'playback_source_item_id' => $this->sourceItemFor($user)->id,
            'movie_id' => $movie->id,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/library/movies?search=arrival&status=watched&sort=rating&per_page=5')
            ->assertOk()
            ->assertJsonPath('items.0.id', $movie->id)
            ->assertJsonPath('items.0.title', 'Arrival')
            ->assertJsonPath('items.0.year', '2016')
            ->assertJsonPath('items.0.runtime', 116)
            ->assertJsonPath('items.0.watched', true)
            ->assertJsonPath('items.0.rating', 9)
            ->assertJsonPath('items.0.hasNote', true)
            ->assertJsonPath('items.0.providerLinked', true)
            ->assertJsonPath('items.0.metadataStatus', 'enriched')
            ->assertJsonPath('pagination.total', 1);

        $this->assertSensitiveKeysHidden($response->json());
    }

    public function test_show_library_and_show_detail_include_seasons_and_episode_rows(): void
    {
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Severance',
            'followed' => true,
            'seen_episodes' => 1,
            'aired_episodes' => 2,
            'latest_seen_at' => now()->subHour(),
            'poster_path' => '/severance.jpg',
            'metadata_refreshed_at' => now(),
        ]);
        $episodeOne = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Good News About Hell',
            'runtime' => 57,
        ]);
        $episodeTwo = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 2,
            'title' => '',
            'runtime' => 52,
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episodeOne->id,
            'watched_at' => now()->subDay(),
            'runtime' => 57,
            'source' => 'import',
        ]);
        Rating::create(['user_id' => $user->id, 'media_type' => 'episode', 'media_id' => $episodeOne->id, 'rating' => 10]);
        Note::create(['user_id' => $user->id, 'media_type' => 'episode', 'media_id' => $episodeTwo->id, 'body' => 'Soon.']);
        MediaLink::create([
            'user_id' => $user->id,
            'playback_source_item_id' => $this->sourceItemFor($user, 'episode-1', 'episode')->id,
            'episode_id' => $episodeOne->id,
            'linked_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/library/shows?search=severance&status=in_progress&sort=progress')
            ->assertOk()
            ->assertJsonPath('items.0.id', $show->id)
            ->assertJsonPath('items.0.title', 'Severance')
            ->assertJsonPath('items.0.progress', 50)
            ->assertJsonPath('items.0.watchedEpisodes', 1)
            ->assertJsonPath('items.0.airedEpisodes', 2)
            ->assertJsonPath('items.0.providerLinked', true);

        $detail = $this->actingAs($user)
            ->getJson("/api/v1/library/shows/{$show->id}")
            ->assertOk()
            ->assertJsonPath('item.seasons.0.seasonNumber', 1)
            ->assertJsonPath('item.seasons.0.watchedEpisodes', 1)
            ->assertJsonPath('item.seasons.0.episodes.0.id', $episodeOne->id)
            ->assertJsonPath('item.seasons.0.episodes.0.watched', true)
            ->assertJsonPath('item.seasons.0.episodes.0.rating', 10)
            ->assertJsonPath('item.seasons.0.episodes.0.providerLinked', true)
            ->assertJsonPath('item.seasons.0.episodes.1.id', $episodeTwo->id)
            ->assertJsonPath('item.seasons.0.episodes.1.title', 'Episode 2')
            ->assertJsonPath('item.seasons.0.episodes.1.hasNote', true);

        $this->assertSensitiveKeysHidden($detail->json());
    }

    public function test_media_details_include_curated_people_and_show_lifecycle_data(): void
    {
        Config::set('tmdb.enabled', true);
        Config::set('tmdb.api_key', 'test-key');
        $user = $this->member();
        $movie = Movie::create([
            'user_id' => $user->id,
            'title' => 'Arrival',
            'tmdb_id' => 329865,
            'overview' => 'A linguist works with the military to communicate with alien lifeforms.',
        ]);
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Completed Story',
            'tmdb_id' => 100,
            'status' => 'Ended',
            'seen_episodes' => 1,
            'aired_episodes' => 1,
        ]);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Finale',
            'air_date' => now()->subDay(),
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episode->id,
            'watched_at' => now(),
            'source' => 'manual',
        ]);

        Http::fake([
            'api.themoviedb.org/3/movie/329865*' => Http::response([
                'id' => 329865,
                'title' => 'Arrival',
                'original_title' => 'Arrival',
                'tagline' => 'Why are they here?',
                'credits' => [
                    'cast' => [['id' => 1, 'name' => 'Amy Adams', 'character' => 'Louise Banks', 'profile_path' => '/amy.jpg', 'order' => 0]],
                    'crew' => [['id' => 2, 'name' => 'Denis Villeneuve', 'job' => 'Director', 'department' => 'Directing', 'profile_path' => '/denis.jpg']],
                ],
                'production_companies' => [['id' => 3, 'name' => 'FilmNation Entertainment']],
                'production_countries' => [['iso_3166_1' => 'US', 'name' => 'United States of America']],
                'spoken_languages' => [['iso_639_1' => 'en', 'english_name' => 'English']],
            ]),
            'api.themoviedb.org/3/tv/100*' => Http::response([
                'id' => 100,
                'name' => 'Completed Story',
                'status' => 'Ended',
                'aggregate_credits' => ['cast' => [], 'crew' => []],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/library/movies/{$movie->id}")
            ->assertOk()
            ->assertJsonPath('item.people.cast.0.name', 'Amy Adams')
            ->assertJsonPath('item.people.cast.0.role', 'Louise Banks')
            ->assertJsonPath('item.people.directors.0.name', 'Denis Villeneuve')
            ->assertJsonPath('item.production.companies.0', 'FilmNation Entertainment')
            ->assertJsonPath('item.tagline', 'Why are they here?');

        $this->actingAs($user)
            ->getJson("/api/v1/library/shows/{$show->id}")
            ->assertOk()
            ->assertJsonPath('item.showState.code', 'ended_completed')
            ->assertJsonPath('item.showState.title', 'SHOW ENDED')
            ->assertJsonPath('item.watchedEpisodes', 1)
            ->assertJsonMissingPath('item.watchedCount');
    }

    public function test_show_library_can_sort_recently_added_for_home(): void
    {
        $user = $this->member();
        $older = Show::create(['user_id' => $user->id, 'title' => 'Older Show']);
        $newer = Show::create(['user_id' => $user->id, 'title' => 'Newer Show']);
        $older->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $newer->forceFill(['updated_at' => now()])->saveQuietly();

        $this->actingAs($user)
            ->getJson('/api/v1/library/shows?sort=newest_added&per_page=2')
            ->assertOk()
            ->assertJsonPath('items.0.id', $newer->id)
            ->assertJsonPath('items.1.id', $older->id);
    }

    public function test_continue_watching_finds_a_later_aired_candidate_without_detail_request_fan_out(): void
    {
        $user = $this->member();

        foreach (range(1, 3) as $offset) {
            $completed = Show::create([
                'user_id' => $user->id,
                'title' => 'Completed '.$offset,
                'seen_episodes' => 1,
                'aired_episodes' => 2,
            ]);
            $watched = Episode::create([
                'user_id' => $user->id,
                'show_id' => $completed->id,
                'season_number' => 1,
                'episode_number' => 1,
                'air_date' => now()->subMonth(),
            ]);
            EpisodeWatch::create([
                'user_id' => $user->id,
                'show_id' => $completed->id,
                'episode_id' => $watched->id,
                'watched_at' => now()->subMinutes($offset),
                'source' => 'manual',
            ]);
        }

        $continuable = Show::create([
            'user_id' => $user->id,
            'title' => 'Continuable',
            'seen_episodes' => 1,
            'aired_episodes' => 2,
        ]);
        $watched = Episode::create([
            'user_id' => $user->id,
            'show_id' => $continuable->id,
            'season_number' => 1,
            'episode_number' => 1,
            'air_date' => now()->subMonth(),
        ]);
        $next = Episode::create([
            'user_id' => $user->id,
            'show_id' => $continuable->id,
            'season_number' => 1,
            'episode_number' => 2,
            'title' => 'Next Memory',
            'air_date' => now()->subDay(),
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $continuable->id,
            'season_number' => 1,
            'episode_number' => 3,
            'title' => 'Future Memory',
            'air_date' => now()->addDay(),
        ]);
        Episode::create([
            'user_id' => $user->id,
            'show_id' => $continuable->id,
            'season_number' => 1,
            'episode_number' => 4,
            'title' => 'Unknown Air Date',
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $continuable->id,
            'episode_id' => $watched->id,
            'watched_at' => now()->subDay(),
            'source' => 'manual',
        ]);

        $other = $this->member('continue-other@example.test');
        $otherShow = Show::create(['user_id' => $other->id, 'title' => 'Private Other Show']);
        $otherWatched = Episode::create([
            'user_id' => $other->id,
            'show_id' => $otherShow->id,
            'season_number' => 1,
            'episode_number' => 1,
            'air_date' => now()->subMonth(),
        ]);
        Episode::create([
            'user_id' => $other->id,
            'show_id' => $otherShow->id,
            'season_number' => 1,
            'episode_number' => 2,
            'title' => 'Private Other Episode',
            'air_date' => now()->subDay(),
        ]);
        EpisodeWatch::create([
            'user_id' => $other->id,
            'show_id' => $otherShow->id,
            'episode_id' => $otherWatched->id,
            'watched_at' => now(),
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/library/continue-watching?limit=3&candidate_limit=30')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.episodeId', $next->id)
            ->assertJsonPath('items.0.showId', $continuable->id)
            ->assertJsonPath('items.0.code', 'S01E02');

        $this->assertSensitiveKeysHidden($response->json());
        $this->assertStringNotContainsString('Private Other', $response->getContent());
    }

    public function test_history_endpoint_paginates_and_searches_watched_movies_and_episodes(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Heat']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'The Bear']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 2,
            'episode_number' => 1,
            'title' => 'Beef',
        ]);
        MovieWatch::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'watched_at' => now()->subDays(2),
            'runtime' => 170,
            'source' => 'manual',
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $episode->id,
            'watched_at' => now()->subDay(),
            'runtime' => 32,
            'source' => 'import',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/library/history?type=episode&search=bear&per_page=1')
            ->assertOk()
            ->assertJsonPath('items.0.kind', 'episode')
            ->assertJsonPath('items.0.showTitle', 'The Bear')
            ->assertJsonPath('items.0.title', 'Beef')
            ->assertJsonPath('items.0.source', 'import')
            ->assertJsonPath('pagination.perPage', 1)
            ->assertJsonPath('pagination.total', 1);

        $this->assertSensitiveKeysHidden($response->json());

        $this->actingAs($user)
            ->getJson('/api/v1/library/history?type=all&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('items.0.kind', 'movie')
            ->assertJsonPath('items.0.title', 'Heat')
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.hasMore', false);
    }

    public function test_continue_watching_selects_the_first_unwatched_episode_per_show_in_sql(): void
    {
        $user = $this->member();
        $show = Show::create([
            'user_id' => $user->id,
            'title' => 'Efficient Show',
            'seen_episodes' => 1,
            'aired_episodes' => 101,
        ]);
        $watched = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 1,
            'air_date' => now()->subDay(),
        ]);
        EpisodeWatch::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'episode_id' => $watched->id,
            'watched_at' => now(),
            'source' => 'manual',
        ]);

        foreach (range(2, 101) as $number) {
            Episode::create([
                'user_id' => $user->id,
                'show_id' => $show->id,
                'season_number' => 1,
                'episode_number' => $number,
                'air_date' => now()->subDay(),
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->actingAs($user)
            ->getJson('/api/v1/library/continue-watching?limit=3&candidate_limit=30')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.episodeNumber', 2);

        $this->assertStringContainsString('row_number() over', implode("\n", $queries));
        $this->assertSensitiveKeysHidden($response->json());
    }

    public function test_global_library_search_returns_movies_shows_and_episodes(): void
    {
        $user = $this->member();
        $movie = Movie::create(['user_id' => $user->id, 'title' => 'Station Eleven Movie']);
        $show = Show::create(['user_id' => $user->id, 'title' => 'Station Eleven']);
        $episode = Episode::create([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'season_number' => 1,
            'episode_number' => 3,
            'title' => 'Hurricane',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/library/search?query=station')
            ->assertOk()
            ->assertJsonPath('movies.0.id', $movie->id)
            ->assertJsonPath('shows.0.id', $show->id)
            ->assertJsonPath('episodes.0.id', $episode->id)
            ->assertJsonPath('episodes.0.showTitle', 'Station Eleven');
    }

    public function test_library_card_endpoints_have_bounded_query_counts(): void
    {
        $user = $this->member();

        foreach (range(1, 24) as $number) {
            $movie = Movie::create(['user_id' => $user->id, 'title' => 'Movie '.$number]);
            MovieWatch::create([
                'user_id' => $user->id,
                'movie_id' => $movie->id,
                'watched_at' => now()->subMinutes($number),
                'source' => 'manual',
            ]);

            $show = Show::create(['user_id' => $user->id, 'title' => 'Show '.$number]);
            $episode = Episode::create([
                'user_id' => $user->id,
                'show_id' => $show->id,
                'season_number' => 1,
                'episode_number' => $number,
                'title' => 'Episode '.$number,
            ]);
            EpisodeWatch::create([
                'user_id' => $user->id,
                'show_id' => $show->id,
                'episode_id' => $episode->id,
                'watched_at' => now()->subMinutes($number),
                'source' => 'manual',
            ]);
        }

        $service = app(LibraryBrowserService::class);

        $this->assertQueryBudget(10, fn () => $service->movies($user, ['per_page' => 24]));
        $this->assertQueryBudget(12, fn () => $service->shows($user, ['per_page' => 24]));
        $this->assertQueryBudget(22, fn () => $service->search($user, ['query' => '1']));
    }

    public function test_continue_watching_query_count_is_bounded_by_batch_not_candidate_count(): void
    {
        $user = $this->member();

        foreach (range(1, 20) as $number) {
            $show = Show::create([
                'user_id' => $user->id,
                'title' => 'Continuable '.$number,
                'seen_episodes' => 1,
                'aired_episodes' => 2,
            ]);
            $watched = Episode::create([
                'user_id' => $user->id,
                'show_id' => $show->id,
                'season_number' => 1,
                'episode_number' => 1,
                'air_date' => now()->subDays(2),
            ]);
            Episode::create([
                'user_id' => $user->id,
                'show_id' => $show->id,
                'season_number' => 1,
                'episode_number' => 2,
                'air_date' => now()->subDay(),
            ]);
            EpisodeWatch::create([
                'user_id' => $user->id,
                'show_id' => $show->id,
                'episode_id' => $watched->id,
                'watched_at' => now()->subMinutes($number),
                'source' => 'manual',
            ]);
        }

        $this->assertQueryBudget(
            12,
            fn () => app(LibraryBrowserService::class)->continueWatching($user, ['limit' => 3, 'candidate_limit' => 20]),
        );
    }

    public function test_user_cannot_access_another_users_show_or_episode_browser_payloads(): void
    {
        $user = $this->member();
        $other = $this->member('other@example.test');
        $otherShow = Show::create(['user_id' => $other->id, 'title' => 'Private Show']);
        $otherEpisode = Episode::create([
            'user_id' => $other->id,
            'show_id' => $otherShow->id,
            'title' => 'Private Episode',
        ]);
        Movie::create(['user_id' => $other->id, 'title' => 'Private Movie']);

        $this->actingAs($user)
            ->getJson("/api/v1/library/shows/{$otherShow->id}")
            ->assertNotFound();
        $this->actingAs($user)
            ->getJson("/api/v1/library/episodes/{$otherEpisode->id}")
            ->assertNotFound();
        $this->actingAs($user)
            ->getJson('/api/v1/library/search?query=private')
            ->assertOk()
            ->assertJsonPath('movies', [])
            ->assertJsonPath('shows', [])
            ->assertJsonPath('episodes', []);
    }

    private function member(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
    }

    private function assertQueryBudget(int $maximum, callable $callback): void
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        $this->assertLessThanOrEqual($maximum, $count, "Expected at most {$maximum} queries, executed {$count}.");
    }

    private function sourceItemFor(User $user, string $externalId = 'item-1', string $kind = 'movie'): PlaybackSourceItem
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
            'external_id' => $externalId,
            'kind' => $kind,
            'title' => 'Provider Item',
            'status' => 'available',
            'stream_url' => 'https://private.example.test/stream/item',
            'stream_url_hash' => hash('sha256', 'https://private.example.test/stream/item'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSensitiveKeysHidden(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach (['stream_url', 'streamUrl', 'playbackUrl', 'provider_url', 'playlist_url', 'provider_secret', 'api_key', 'secret-token', 'https://private.example.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }
}
