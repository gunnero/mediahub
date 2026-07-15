<?php

namespace Tests\Feature;

use App\Enums\FriendInviteStatus;
use App\Enums\FriendshipStatus;
use App\Models\Alert;
use App\Models\FriendInvite;
use App\Models\MediaList;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilesAndFriendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_profile_hides_content_and_never_exposes_email_or_internal_id(): void
    {
        $owner = User::factory()->create();
        Movie::create([
            'user_id' => $owner->id,
            'title' => 'Private favorite',
            'runtime' => 100,
            'is_to_watch' => false,
        ]);

        $profile = $this->actingAs($owner)->getJson('/api/v1/profile')->assertOk()->json('profile');
        $this->assertSame('private', $profile['profileVisibility']);
        $this->assertFalse($profile['publicProfileEnabled']);
        auth()->logout();

        $response = $this->getJson('/api/v1/profiles/'.$profile['slug'])
            ->assertOk()
            ->assertJsonPath('profile.isPrivate', true)
            ->assertJsonPath('profile.contentVisible', false)
            ->assertJsonMissingPath('profile.email')
            ->assertJsonMissingPath('profile.id')
            ->assertJsonMissingPath('content.statistics')
            ->assertJsonMissingPath('content.favoriteMovies');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($owner->email, $encoded);
        $this->assertStringNotContainsString('Private favorite', $encoded);
    }

    public function test_public_profile_shows_only_explicitly_enabled_content(): void
    {
        $owner = User::factory()->create();
        $movie = Movie::create([
            'user_id' => $owner->id,
            'title' => 'Selected movie',
            'runtime' => 120,
            'release_date' => '2025-01-01',
            'poster_url' => 'https://private-provider.example/secret-poster.jpg',
            'is_to_watch' => false,
        ]);
        $hiddenShow = Show::create([
            'user_id' => $owner->id,
            'title' => 'Hidden show',
            'followed' => true,
            'runtime' => 45,
        ]);

        $this->actingAs($owner)->patchJson('/api/v1/profile', [
            'display_name' => 'Public member',
            'bio' => 'A short public bio.',
            'favorite_movie_ids' => [$movie->id],
            'favorite_show_ids' => [$hiddenShow->id],
        ])->assertOk();

        $this->patchJson('/api/v1/profile/privacy', [
            'public_profile_enabled' => true,
            'profile_visibility' => 'public',
            'show_statistics' => true,
            'show_favorite_movies' => true,
            'show_favorite_shows' => false,
            'show_public_lists' => false,
            'show_recent_activity' => false,
            'allow_friend_requests' => true,
            'allow_profile_sharing' => true,
            'allow_search_discovery' => true,
        ])->assertOk();

        $slug = $this->getJson('/api/v1/profile')->json('profile.slug');
        auth()->logout();
        $response = $this->getJson('/api/v1/profiles/'.$slug)
            ->assertOk()
            ->assertJsonPath('profile.contentVisible', true)
            ->assertJsonPath('profile.displayName', 'Public member')
            ->assertJsonPath('content.favoriteMovies.0.title', 'Selected movie')
            ->assertJsonMissingPath('content.favoriteShows')
            ->assertJsonMissingPath('content.recentActivity')
            ->assertJsonMissingPath('profile.email')
            ->assertJsonMissingPath('profile.role');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($owner->email, $encoded);
        $this->assertStringNotContainsString('Hidden show', $encoded);
        $this->assertStringNotContainsString('provider', strtolower($encoded));
        $this->assertStringNotContainsString('secret-poster', $encoded);
    }

    public function test_friends_only_profile_requires_an_accepted_friendship(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        $this->actingAs($owner)->patchJson('/api/v1/profile/privacy', [
            'public_profile_enabled' => true,
            'profile_visibility' => 'friends',
            'show_statistics' => true,
        ])->assertOk();
        $ownerSlug = $this->getJson('/api/v1/profile')->json('profile.slug');

        $this->actingAs($viewer)->getJson('/api/v1/profiles/'.$ownerSlug)
            ->assertOk()
            ->assertJsonPath('profile.contentVisible', false);

        $this->patchJson('/api/v1/profile/privacy', ['allow_friend_requests' => true])->assertOk();
        $viewerSlug = $this->getJson('/api/v1/profile')->json('profile.slug');
        $friendshipId = $this->actingAs($owner)
            ->postJson('/api/v1/friends/request/'.$viewerSlug)
            ->assertCreated()
            ->json('friendship.id');
        $this->actingAs($viewer)
            ->postJson('/api/v1/friends/'.$friendshipId.'/accept')
            ->assertOk();

        $this->getJson('/api/v1/profiles/'.$ownerSlug)
            ->assertOk()
            ->assertJsonPath('profile.contentVisible', true)
            ->assertJsonStructure(['content' => ['statistics']]);
    }

    public function test_friendship_requests_are_owned_unique_and_block_aware(): void
    {
        $requester = User::factory()->create();
        $addressee = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($addressee)->patchJson('/api/v1/profile/privacy', ['allow_friend_requests' => true])->assertOk();
        $addresseeSlug = $this->getJson('/api/v1/profile')->json('profile.slug');
        $this->actingAs($requester)->patchJson('/api/v1/profile/privacy', ['allow_friend_requests' => true])->assertOk();
        $requesterSlug = $this->getJson('/api/v1/profile')->json('profile.slug');

        $friendshipId = $this->actingAs($requester)
            ->postJson('/api/v1/friends/request/'.$addresseeSlug)
            ->assertCreated()
            ->assertJsonPath('friendship.status', FriendshipStatus::Pending->value)
            ->json('friendship.id');

        $this->postJson('/api/v1/friends/request/'.$addresseeSlug)->assertUnprocessable();
        $this->actingAs($outsider)->postJson('/api/v1/friends/'.$friendshipId.'/accept')->assertNotFound();
        $this->actingAs($addressee)->postJson('/api/v1/friends/'.$friendshipId.'/decline')->assertOk();

        $blockedId = $this->postJson('/api/v1/friends/'.$requesterSlug.'/block')
            ->assertOk()
            ->assertJsonPath('friendship.status', FriendshipStatus::Blocked->value)
            ->json('friendship.id');
        $this->actingAs($requester)
            ->postJson('/api/v1/friends/'.$addresseeSlug.'/block')
            ->assertUnprocessable();
        $this->assertDatabaseHas('friendships', [
            'id' => $blockedId,
            'status' => FriendshipStatus::Blocked->value,
            'blocked_by_user_id' => $addressee->id,
        ]);
        $this->actingAs($requester)->deleteJson('/api/v1/friends/'.$blockedId)->assertNotFound();
        $this->actingAs($requester)->postJson('/api/v1/friends/request/'.$addresseeSlug)->assertUnprocessable();
        $this->postJson('/api/v1/friends/request/'.$requesterSlug)->assertUnprocessable();
    }

    public function test_friend_request_acceptance_creates_safe_in_app_notifications(): void
    {
        $requester = User::factory()->create(['name' => 'Requester']);
        $addressee = User::factory()->create(['name' => 'Addressee']);
        $this->actingAs($addressee)->patchJson('/api/v1/profile/privacy', ['allow_friend_requests' => true])->assertOk();
        $slug = $this->getJson('/api/v1/profile')->json('profile.slug');

        $friendshipId = $this->actingAs($requester)
            ->postJson('/api/v1/friends/request/'.$slug)
            ->assertCreated()
            ->json('friendship.id');

        $received = Alert::forUser($addressee)->where('category', 'social')->firstOrFail();
        $this->assertStringNotContainsString($requester->email, json_encode($received->toArray(), JSON_THROW_ON_ERROR));

        $this->actingAs($addressee)->postJson('/api/v1/friends/'.$friendshipId.'/accept')->assertOk();
        $accepted = Alert::forUser($requester)->where('category', 'social')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($addressee->email, json_encode($accepted->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_friend_invite_token_is_hashed_expires_and_does_not_expose_inviter_email(): void
    {
        $inviter = User::factory()->create();
        $response = $this->actingAs($inviter)
            ->postJson('/api/v1/friend-invites')
            ->assertCreated()
            ->assertJsonMissingPath('invite.inviterEmail');

        $url = $response->json('invite.url');
        $token = basename(parse_url($url, PHP_URL_PATH));
        $invite = FriendInvite::query()->firstOrFail();
        $this->assertNotSame($token, $invite->token_hash);

        $this->getJson('/api/v1/friend-invites/'.$token)
            ->assertOk()
            ->assertJsonMissingPath('invite.inviter.email');
        $this->assertSame(FriendInviteStatus::Opened, $invite->refresh()->status);

        $invite->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->getJson('/api/v1/friend-invites/'.$token)->assertStatus(410);
        $this->assertSame(FriendInviteStatus::Expired, $invite->refresh()->status);
    }

    public function test_friend_invite_requires_explicit_acceptance_and_creates_a_safe_friendship(): void
    {
        $inviter = User::factory()->create();
        $acceptingUser = User::factory()->create();
        $url = $this->actingAs($inviter)->postJson('/api/v1/friend-invites')->assertCreated()->json('invite.url');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertDatabaseCount('friendships', 0);
        $response = $this->actingAs($acceptingUser)
            ->postJson('/api/v1/friend-invites/'.$token.'/accept')
            ->assertOk()
            ->assertJsonPath('status', FriendInviteStatus::Accepted->value)
            ->assertJsonPath('friendship.status', FriendshipStatus::Accepted->value);

        $this->assertDatabaseHas('friendships', [
            'id' => $response->json('friendship.id'),
            'status' => FriendshipStatus::Accepted->value,
        ]);
        $alert = Alert::forUser($inviter)->where('category', 'social')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($acceptingUser->email, json_encode($alert->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_profile_options_are_limited_to_owned_media_and_public_lists(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Movie::create(['user_id' => $owner->id, 'title' => 'Owned movie', 'is_to_watch' => false]);
        Movie::create(['user_id' => $other->id, 'title' => 'Other movie', 'is_to_watch' => false]);
        Show::create(['user_id' => $owner->id, 'title' => 'Owned show']);
        Show::create(['user_id' => $other->id, 'title' => 'Other show']);
        MediaList::create(['user_id' => $owner->id, 'name' => 'Public picks', 'visibility' => 'public']);
        MediaList::create(['user_id' => $owner->id, 'name' => 'Private picks', 'visibility' => 'private']);
        MediaList::create(['user_id' => $other->id, 'name' => 'Other picks', 'visibility' => 'public']);

        $response = $this->actingAs($owner)->getJson('/api/v1/profile/options')
            ->assertOk()
            ->assertJsonPath('movies.0.title', 'Owned movie')
            ->assertJsonPath('shows.0.title', 'Owned show')
            ->assertJsonPath('publicLists.0.title', 'Public picks');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Other movie', $encoded);
        $this->assertStringNotContainsString('Other show', $encoded);
        $this->assertStringNotContainsString('Private picks', $encoded);
        $this->assertStringNotContainsString('Other picks', $encoded);
    }

    public function test_profile_slug_is_unique_reserved_slugs_are_blocked_and_other_users_cannot_be_edited(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->patchJson('/api/v1/profile', [
            'username' => 'first_member',
            'profile_slug' => 'first-member',
        ])->assertOk();

        $this->actingAs($second)->patchJson('/api/v1/profile', ['profile_slug' => 'first-member'])
            ->assertUnprocessable();
        $this->patchJson('/api/v1/profile', ['profile_slug' => 'admin'])
            ->assertUnprocessable();

        $before = $first->fresh()->display_name;
        $this->patchJson('/api/v1/profile', ['display_name' => 'Second member'])->assertOk();
        $this->assertSame($before, $first->fresh()->display_name);
        $this->assertSame('Second member', $second->fresh()->display_name);
    }

    public function test_profile_supports_full_name_iso_country_and_private_by_default_avatar_visibility(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/profile', [
            'display_name' => 'Gunner',
            'full_name' => 'Aleksandar Dimovski',
            'country' => 'MK',
        ])->assertOk()
            ->assertJsonPath('profile.fullName', 'Aleksandar Dimovski')
            ->assertJsonPath('profile.country', 'MK')
            ->assertJsonPath('privacy.showAvatar', false);

        $this->patchJson('/api/v1/profile', ['country' => 'XX'])->assertUnprocessable();

        $upload = $this->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 700, 500),
        ], ['Accept' => 'application/json'])->assertCreated();
        $avatarUrl = $upload->json('profile.avatar');
        $slug = $upload->json('profile.slug');
        $this->get($avatarUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        auth()->logout();
        $this->getJson('/api/v1/profiles/'.$slug)->assertOk()->assertJsonPath('profile.avatar', null);
        $this->get($avatarUrl)->assertNotFound();
        $this->actingAs($user)->patchJson('/api/v1/profile/privacy', [
            'show_avatar' => true,
            'public_profile_enabled' => true,
            'profile_visibility' => 'public',
        ])->assertOk();
        auth()->logout();
        $this->getJson('/api/v1/profiles/'.$slug)
            ->assertOk()
            ->assertJsonPath('profile.avatar', $avatarUrl);
        $cacheControl = $this->get($avatarUrl)->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('public', (string) $cacheControl);
        $this->assertStringContainsString('must-revalidate', (string) $cacheControl);
    }

    public function test_avatar_upload_accepts_supported_images_generates_thumbnails_replaces_and_deletes_safely(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $previousUrl = null;

        foreach ([
            UploadedFile::fake()->image('avatar.jpg', 700, 500),
            UploadedFile::fake()->image('avatar.png', 500, 700),
            $this->fakeWebp(),
        ] as $index => $upload) {
            $previous = $user->fresh()->avatar_variants ?? [];
            $response = $this->post('/api/v1/profile/avatar', ['avatar' => $upload, 'user_id' => $other->id], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('profile.avatarVariants.512', fn (string $value): bool => str_contains($value, '/avatar/512?v='));
            $currentUrl = $response->json('profile.avatar');
            if ($previousUrl !== null) {
                $this->assertNotSame($previousUrl, $currentUrl);
            }
            $previousUrl = $currentUrl;

            $user->refresh();
            $this->assertNotNull($user->avatar_path);
            $this->assertNull($other->fresh()->avatar_path);
            $this->assertStringNotContainsString('/avatars/'.$user->id.'/', (string) $user->avatar_path);
            foreach ([512, 128, 64, 32] as $size) {
                $path = $user->avatar_variants[(string) $size];
                Storage::disk('local')->assertExists($path);
                $dimensions = getimagesize(Storage::disk('local')->path($path));
                $this->assertSame($size, $dimensions[0]);
                $this->assertSame($size, $dimensions[1]);
                $this->assertSame('image/jpeg', $dimensions['mime']);
                $this->assertStringNotContainsString("Exif\0\0", (string) file_get_contents(Storage::disk('local')->path($path)));
            }
            if ($index > 0) {
                foreach ($previous as $path) {
                    Storage::disk('local')->assertMissing($path);
                }
            }
        }

        $user->refresh();
        $paths = $user->avatar_variants;
        $avatarUrl = $this->actingAs($user)->getJson('/api/v1/profile')->json('profile.avatar');
        $this->get($avatarUrl)->assertOk();
        $this->actingAs($other)->get($avatarUrl)->assertNotFound();
        $this->get('/api/v1/profiles/'.$user->profile_slug.'/avatar/256')->assertNotFound();

        $this->actingAs($user)->deleteJson('/api/v1/profile/avatar')
            ->assertOk()
            ->assertJsonPath('profile.avatar', null);
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertNull($user->fresh()->avatar_path);
        $this->get($avatarUrl)->assertNotFound();
    }

    public function test_avatar_upload_rejects_oversized_and_invalid_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('large.jpg')->size(5121)], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->createWithContent('avatar.jpg', '<?php echo "no";')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_self_friendship_is_rejected_and_friend_lists_never_leak_email(): void
    {
        $user = User::factory()->create();
        $slug = $this->actingAs($user)->getJson('/api/v1/profile')->json('profile.slug');

        $this->postJson('/api/v1/friends/request/'.$slug)->assertUnprocessable();
        $response = $this->getJson('/api/v1/friends')->assertOk();
        $this->assertStringNotContainsString($user->email, json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_friend_invite_creation_has_an_independent_rate_limit_bucket(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($request = 0; $request < 5; $request++) {
            $this->patchJson('/api/v1/profile/privacy', [
                'show_statistics' => $request % 2 === 0,
            ])->assertOk();
        }

        $this->postJson('/api/v1/friend-invites')->assertCreated();
    }

    private function fakeWebp(): UploadedFile
    {
        $image = imagecreatetruecolor(640, 480);
        $background = imagecolorallocate($image, 30, 80, 120);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagewebp($image, null, 85);
        $contents = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('avatar.webp', (string) $contents);
    }
}
