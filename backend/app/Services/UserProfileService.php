<?php

namespace App\Services;

use App\Enums\FriendshipStatus;
use App\Enums\ProfileVisibility;
use App\Models\Friendship;
use App\Models\MediaList;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Illuminate\Support\Str;

class UserProfileService
{
    public const RESERVED_SLUGS = [
        'admin', 'api', 'assets', 'discover', 'friends', 'help', 'invite', 'invites',
        'login', 'logout', 'mediahub', 'movies', 'privacy', 'profile', 'settings',
        'shows', 'static', 'support', 'u',
    ];

    public function __construct(
        private readonly MediaLibraryService $mediaLibrary,
        private readonly UserAvatarService $avatars,
    ) {}

    public function ensureProfile(User $user): User
    {
        $changes = [];
        if (blank($user->username)) {
            $changes['username'] = $this->uniqueUsername((string) $user->name, $user->id);
        }
        if (blank($user->profile_slug)) {
            $changes['profile_slug'] = $this->uniqueSlug((string) $user->name, $user->id);
        }
        if (blank($user->display_name)) {
            $changes['display_name'] = $user->name;
        }
        if (! $user->joined_at) {
            $changes['joined_at'] = $user->created_at ?? now();
        }

        if ($changes !== []) {
            $user->forceFill($changes)->saveQuietly();
        }

        return $user->refresh();
    }

    /** @param array<string, mixed> $data */
    public function updateIdentity(User $user, array $data): User
    {
        $this->ensureProfile($user);
        $updates = collect($data)->only([
            'username', 'display_name', 'full_name', 'bio', 'profile_slug', 'country', 'favorite_genres',
        ])->all();

        if (array_key_exists('country', $updates)) {
            $updates['country'] = filled($updates['country']) ? Str::upper((string) $updates['country']) : null;
        }

        if (array_key_exists('favorite_genres', $updates)) {
            $updates['favorite_genres'] = collect($updates['favorite_genres'] ?? [])
                ->map(fn (mixed $genre): string => trim((string) $genre))
                ->filter()
                ->unique(fn (string $genre): string => Str::lower($genre))
                ->take(12)
                ->values()
                ->all();
        }

        if (array_key_exists('favorite_movie_ids', $data)) {
            $updates['favorite_movie_ids'] = $this->ownedIds(Movie::forUser($user), $data['favorite_movie_ids']);
        }
        if (array_key_exists('favorite_show_ids', $data)) {
            $updates['favorite_show_ids'] = $this->ownedIds(Show::forUser($user), $data['favorite_show_ids']);
        }
        if (array_key_exists('featured_list_ids', $data)) {
            $updates['featured_list_ids'] = $this->ownedIds(
                MediaList::forUser($user)->where('visibility', 'public'),
                $data['featured_list_ids'],
            );
        }

        $user->fill($updates)->save();

        return $user->refresh();
    }

    /** @param array<string, mixed> $data */
    public function updatePrivacy(User $user, array $data): User
    {
        $user->fill(collect($data)->only([
            'public_profile_enabled',
            'show_avatar',
            'profile_visibility',
            'show_statistics',
            'show_favorite_movies',
            'show_favorite_shows',
            'show_public_lists',
            'show_recent_activity',
            'allow_friend_requests',
            'allow_profile_sharing',
            'allow_search_discovery',
        ])->all())->save();

        return $user->refresh();
    }

    /** @return array<string, mixed> */
    public function ownPayload(User $user): array
    {
        $user = $this->ensureProfile($user);

        return [
            'profile' => [
                'username' => $user->username,
                'displayName' => $user->display_name,
                'fullName' => $user->full_name,
                'email' => $user->email,
                'bio' => $user->bio,
                'avatar' => $user->avatar_path,
                'avatarVariants' => $this->avatars->urls($user->avatar_variants),
                'slug' => $user->profile_slug,
                'country' => $user->country,
                'favoriteGenres' => $user->favorite_genres ?? [],
                'favoriteMovieIds' => $user->favorite_movie_ids ?? [],
                'favoriteShowIds' => $user->favorite_show_ids ?? [],
                'featuredListIds' => $user->featured_list_ids ?? [],
                'joinedAt' => $user->joined_at?->toIso8601String(),
                'lastActiveAt' => $user->last_active_at?->toIso8601String(),
                'profileVisibility' => $user->profile_visibility->value,
                'publicProfileEnabled' => $user->public_profile_enabled,
                'shareUrl' => $this->shareUrl($user),
            ],
            'privacy' => $this->privacyPayload($user),
        ];
    }

    /** @return array{movies:list<array{id:int,title:string}>,shows:list<array{id:int,title:string}>,publicLists:list<array{id:int,title:string}>} */
    public function profileOptions(User $user): array
    {
        return [
            'movies' => Movie::forUser($user)
                ->orderBy('title')
                ->limit(1000)
                ->get(['id', 'title'])
                ->map(fn (Movie $movie): array => ['id' => $movie->id, 'title' => $movie->title])
                ->values()
                ->all(),
            'shows' => Show::forUser($user)
                ->orderBy('title')
                ->limit(500)
                ->get(['id', 'title'])
                ->map(fn (Show $show): array => ['id' => $show->id, 'title' => $show->title])
                ->values()
                ->all(),
            'publicLists' => MediaList::forUser($user)
                ->where('visibility', 'public')
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name'])
                ->map(fn (MediaList $list): array => ['id' => $list->id, 'title' => $list->name])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function publicProfile(User $profileUser, ?User $viewer = null, bool $asPublic = false): array
    {
        $profileUser = $this->ensureProfile($profileUser);
        $viewer = $viewer ? $this->ensureProfile($viewer) : null;
        $ownerView = ! $asPublic && $viewer?->id === $profileUser->id;
        $friend = ! $asPublic && $viewer && $viewer->id !== $profileUser->id
            ? $this->acceptedFriendship($profileUser, $viewer)
            : null;
        $visibility = $profileUser->public_profile_enabled
            ? $profileUser->profile_visibility
            : ProfileVisibility::Private;
        $contentVisible = $ownerView
            || ($profileUser->public_profile_enabled && $visibility === ProfileVisibility::Public)
            || ($profileUser->public_profile_enabled && $visibility === ProfileVisibility::Friends && $friend !== null);

        $profile = [
            ...$this->publicIdentity($profileUser),
            'visibility' => $visibility->value,
            'isPrivate' => ! $contentVisible,
            'contentVisible' => $contentVisible,
            'canShare' => $profileUser->public_profile_enabled && $profileUser->allow_profile_sharing,
        ];

        if ($contentVisible) {
            $profile += [
                'bio' => $profileUser->bio,
                'country' => $profileUser->country,
                'favoriteGenres' => $profileUser->favorite_genres ?? [],
                'memberSince' => $profileUser->joined_at?->toDateString(),
            ];
        }

        $content = [];
        if ($contentVisible && $profileUser->show_statistics) {
            $stats = $this->mediaLibrary->statsFor($profileUser);
            $content['statistics'] = collect($stats)->only([
                'episodesWatched', 'moviesWatched', 'hoursWatched', 'showsFollowed',
            ])->all();
        }
        if ($contentVisible && $profileUser->show_favorite_movies) {
            $content['favoriteMovies'] = $this->favoriteMovies($profileUser);
        }
        if ($contentVisible && $profileUser->show_favorite_shows) {
            $content['favoriteShows'] = $this->favoriteShows($profileUser);
        }
        if ($contentVisible && $profileUser->show_public_lists) {
            $content['publicLists'] = $this->featuredLists($profileUser);
        }

        return [
            'profile' => $profile,
            'content' => $content,
            'relationship' => $this->relationshipPayload($profileUser, $viewer, $asPublic),
            'shareUrl' => $profileUser->public_profile_enabled && $profileUser->allow_profile_sharing
                ? $this->shareUrl($profileUser)
                : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function search(User $viewer, string $query): array
    {
        $safeQuery = trim($query);
        if (mb_strlen($safeQuery) < 2) {
            return [];
        }

        return User::query()
            ->whereKeyNot($viewer->id)
            ->where('public_profile_enabled', true)
            ->where('allow_search_discovery', true)
            ->where(function ($profiles) use ($safeQuery): void {
                $profiles->where('username', 'like', '%'.$safeQuery.'%')
                    ->orWhere('display_name', 'like', '%'.$safeQuery.'%');
            })
            ->orderBy('display_name')
            ->limit(20)
            ->get()
            ->map(fn (User $user): array => [
                ...$this->publicIdentity($user),
                'relationship' => $this->relationshipPayload($user, $viewer),
            ])
            ->values()
            ->all();
    }

    /** @return array{slug:string,username:string,displayName:string,avatar:?string} */
    public function publicIdentity(User $user): array
    {
        $user = $this->ensureProfile($user);

        return [
            'slug' => (string) $user->profile_slug,
            'username' => (string) $user->username,
            'displayName' => (string) ($user->display_name ?: $user->username),
            'avatar' => $user->show_avatar ? $user->avatar_path : null,
        ];
    }

    public function isReservedSlug(string $slug): bool
    {
        return in_array(Str::lower($slug), self::RESERVED_SLUGS, true);
    }

    private function uniqueUsername(string $name, int $userId): string
    {
        $base = Str::limit(Str::lower(Str::slug($name, '_')), 30, '') ?: 'member_'.$userId;
        $candidate = $base;
        $counter = 2;
        while (User::query()->where('username', $candidate)->whereKeyNot($userId)->exists()) {
            $candidate = $base.'_'.$counter;
            $counter++;
        }

        return $candidate;
    }

    private function uniqueSlug(string $name, int $userId): string
    {
        $base = Str::limit(Str::lower(Str::slug($name)), 45, '') ?: 'member-'.$userId;
        if ($this->isReservedSlug($base)) {
            $base = 'member-'.$base;
        }
        $candidate = $base;
        $counter = 2;
        while (User::query()->where('profile_slug', $candidate)->whereKeyNot($userId)->exists()) {
            $candidate = $base.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    /** @return array<string, bool|string> */
    private function privacyPayload(User $user): array
    {
        return [
            'publicProfileEnabled' => $user->public_profile_enabled,
            'showAvatar' => $user->show_avatar,
            'profileVisibility' => $user->profile_visibility->value,
            'showStatistics' => $user->show_statistics,
            'showFavoriteMovies' => $user->show_favorite_movies,
            'showFavoriteShows' => $user->show_favorite_shows,
            'showPublicLists' => $user->show_public_lists,
            'showRecentActivity' => $user->show_recent_activity,
            'allowFriendRequests' => $user->allow_friend_requests,
            'allowProfileSharing' => $user->allow_profile_sharing,
            'allowSearchDiscovery' => $user->allow_search_discovery,
        ];
    }

    /** @param mixed $values @return list<int> */
    private function ownedIds($query, mixed $values): array
    {
        $ids = collect(is_array($values) ? $values : [])->map(fn (mixed $id): int => (int) $id)->filter()->unique();

        return $query->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    private function acceptedFriendship(User $first, User $second): ?Friendship
    {
        return Friendship::query()
            ->accepted()
            ->where('pair_key', FriendshipService::pairKey($first, $second))
            ->first();
    }

    /** @return array{status:string,canRequest:bool} */
    private function relationshipPayload(User $profileUser, ?User $viewer, bool $asPublic = false): array
    {
        if ($asPublic || ! $viewer) {
            return ['status' => 'guest', 'canRequest' => false];
        }
        if ($viewer->id === $profileUser->id) {
            return ['status' => 'self', 'canRequest' => false];
        }

        $friendship = Friendship::query()->where('pair_key', FriendshipService::pairKey($profileUser, $viewer))->first();
        if (! $friendship) {
            return [
                'status' => 'none',
                'canRequest' => $profileUser->allow_friend_requests,
            ];
        }

        $status = $friendship->status->value;
        if ($friendship->status === FriendshipStatus::Pending) {
            $status = $friendship->requester_user_id === $viewer->id ? 'pending_outgoing' : 'pending_incoming';
        }

        return ['status' => $status, 'canRequest' => false];
    }

    /** @return list<array<string, mixed>> */
    private function favoriteMovies(User $user): array
    {
        return Movie::forUser($user)
            ->whereIn('id', $user->favorite_movie_ids ?? [])
            ->get()
            ->map(fn (Movie $movie): array => [
                'title' => $movie->title,
                'poster' => $this->publicPosterUrl($movie->poster_path),
                'year' => $movie->release_date?->format('Y'),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function favoriteShows(User $user): array
    {
        return Show::forUser($user)
            ->whereIn('id', $user->favorite_show_ids ?? [])
            ->get()
            ->map(fn (Show $show): array => [
                'title' => $show->title,
                'poster' => $this->publicPosterUrl($show->poster_path),
                'year' => $show->first_air_date?->format('Y'),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function featuredLists(User $user): array
    {
        return MediaList::forUser($user)
            ->where('visibility', 'public')
            ->whereIn('id', $user->featured_list_ids ?? [])
            ->withCount('items')
            ->get()
            ->map(fn (MediaList $list): array => [
                'name' => $list->name,
                'description' => $list->description,
                'itemCount' => $list->items_count,
            ])->values()->all();
    }

    private function publicPosterUrl(?string $path): ?string
    {
        return filled($path) ? rtrim((string) config('tmdb.image_base_url'), '/').'/w500'.$path : null;
    }

    private function shareUrl(User $user): string
    {
        return rtrim((string) config('app.url'), '/').'/u/'.$user->profile_slug;
    }
}
