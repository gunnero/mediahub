<?php

namespace App\Services;

use App\Enums\FriendshipStatus;
use App\Models\Alert;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FriendshipService
{
    public function __construct(
        private readonly UserProfileService $profiles,
    ) {}

    public static function pairKey(User $first, User $second): string
    {
        return min($first->id, $second->id).':'.max($first->id, $second->id);
    }

    public function request(User $requester, User $addressee): Friendship
    {
        $this->assertDifferentUsers($requester, $addressee);
        $this->profiles->ensureProfile($addressee);
        if (! $addressee->allow_friend_requests) {
            throw ValidationException::withMessages(['friend' => 'This member is not accepting friend requests.']);
        }

        $existing = Friendship::query()->where('pair_key', self::pairKey($requester, $addressee))->first();
        if ($existing?->status === FriendshipStatus::Blocked) {
            throw ValidationException::withMessages(['friend' => 'A friend request is not available.']);
        }
        if ($existing) {
            throw ValidationException::withMessages(['friend' => 'A friendship request already exists.']);
        }

        $friendship = Friendship::create([
            'requester_user_id' => $requester->id,
            'addressee_user_id' => $addressee->id,
            'pair_key' => self::pairKey($requester, $addressee),
            'status' => FriendshipStatus::Pending,
        ]);
        $this->notify($addressee, 'friend-request:'.$friendship->id, 'Friend request received', $requester, $friendship);

        return $friendship->refresh();
    }

    public function accept(User $user, Friendship $friendship): Friendship
    {
        $friendship = $this->ownedIncoming($user, $friendship);
        if ($friendship->status !== FriendshipStatus::Pending) {
            throw ValidationException::withMessages(['friendship' => 'This request is no longer pending.']);
        }

        $friendship->forceFill([
            'status' => FriendshipStatus::Accepted,
            'accepted_at' => now(),
            'declined_at' => null,
            'blocked_at' => null,
            'blocked_by_user_id' => null,
        ])->save();
        $this->notify($friendship->requester, 'friend-accepted:'.$friendship->id, 'Friend request accepted', $user, $friendship);

        return $friendship->refresh();
    }

    public function decline(User $user, Friendship $friendship): Friendship
    {
        $friendship = $this->ownedIncoming($user, $friendship);
        if ($friendship->status !== FriendshipStatus::Pending) {
            throw ValidationException::withMessages(['friendship' => 'This request is no longer pending.']);
        }

        $friendship->forceFill([
            'status' => FriendshipStatus::Declined,
            'declined_at' => now(),
        ])->save();

        return $friendship->refresh();
    }

    public function remove(User $user, Friendship $friendship): void
    {
        if (! in_array($user->id, [$friendship->requester_user_id, $friendship->addressee_user_id], true)) {
            throw new ModelNotFoundException;
        }
        if ($friendship->status === FriendshipStatus::Blocked && $friendship->blocked_by_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        $friendship->delete();
    }

    public function block(User $user, User $target): Friendship
    {
        $this->assertDifferentUsers($user, $target);

        return DB::transaction(function () use ($target, $user): Friendship {
            $friendship = Friendship::query()
                ->where('pair_key', self::pairKey($user, $target))
                ->lockForUpdate()
                ->first();

            if (! $friendship) {
                $friendship = new Friendship([
                    'requester_user_id' => $user->id,
                    'addressee_user_id' => $target->id,
                    'pair_key' => self::pairKey($user, $target),
                ]);
            }

            if ($friendship->status === FriendshipStatus::Blocked && $friendship->blocked_by_user_id !== $user->id) {
                throw ValidationException::withMessages(['friendship' => 'This action is not available.']);
            }

            $friendship->forceFill([
                'status' => FriendshipStatus::Blocked,
                'blocked_by_user_id' => $user->id,
                'blocked_at' => now(),
                'accepted_at' => null,
                'declined_at' => null,
            ])->save();

            return $friendship->refresh();
        });
    }

    public function acceptInvite(User $inviter, User $acceptingUser): Friendship
    {
        $this->assertDifferentUsers($inviter, $acceptingUser);
        $friendship = Friendship::query()->where('pair_key', self::pairKey($inviter, $acceptingUser))->first();

        if ($friendship?->status === FriendshipStatus::Blocked) {
            throw ValidationException::withMessages(['invite' => 'This invitation cannot create a friendship.']);
        }

        $friendship ??= new Friendship([
            'requester_user_id' => $inviter->id,
            'addressee_user_id' => $acceptingUser->id,
            'pair_key' => self::pairKey($inviter, $acceptingUser),
        ]);
        $friendship->forceFill([
            'status' => FriendshipStatus::Accepted,
            'accepted_at' => now(),
            'declined_at' => null,
            'blocked_at' => null,
            'blocked_by_user_id' => null,
        ])->save();

        return $friendship->refresh();
    }

    /** @return array<string, mixed> */
    public function lists(User $user): array
    {
        $friendships = Friendship::forUser($user)
            ->accepted()
            ->with(['requester', 'addressee'])
            ->latest('accepted_at')
            ->get();

        return [
            'friends' => $friendships->map(fn (Friendship $friendship): array => [
                'friendshipId' => $friendship->id,
                'profile' => $this->profiles->publicIdentity($friendship->otherUser($user), $user),
                'acceptedAt' => $friendship->accepted_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function requests(User $user): array
    {
        $incoming = Friendship::query()
            ->where('addressee_user_id', $user->id)
            ->where('status', FriendshipStatus::Pending)
            ->with('requester')
            ->latest()
            ->get();
        $outgoing = Friendship::query()
            ->where('requester_user_id', $user->id)
            ->where('status', FriendshipStatus::Pending)
            ->with('addressee')
            ->latest()
            ->get();

        return [
            'incoming' => $incoming->map(fn (Friendship $friendship): array => [
                'friendshipId' => $friendship->id,
                'profile' => $this->profiles->publicIdentity($friendship->requester, $user),
                'createdAt' => $friendship->created_at?->toIso8601String(),
            ])->values()->all(),
            'outgoing' => $outgoing->map(fn (Friendship $friendship): array => [
                'friendshipId' => $friendship->id,
                'profile' => $this->profiles->publicIdentity($friendship->addressee, $user),
                'createdAt' => $friendship->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function response(Friendship $friendship, User $viewer): array
    {
        $friendship->loadMissing(['requester', 'addressee']);

        return [
            'id' => $friendship->id,
            'status' => $friendship->status->value,
            'direction' => $friendship->requester_user_id === $viewer->id ? 'outgoing' : 'incoming',
            'profile' => $this->profiles->publicIdentity($friendship->otherUser($viewer), $viewer),
        ];
    }

    private function ownedIncoming(User $user, Friendship $friendship): Friendship
    {
        if ($friendship->addressee_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        return $friendship->loadMissing('requester');
    }

    private function assertDifferentUsers(User $first, User $second): void
    {
        if ($first->id === $second->id) {
            throw ValidationException::withMessages(['friend' => 'You cannot add yourself as a friend.']);
        }
    }

    private function notify(User $recipient, string $dedupeKey, string $title, User $actor, Friendship $friendship): void
    {
        $identity = $this->profiles->publicIdentity($actor);
        Alert::updateOrCreate(
            ['user_id' => $recipient->id, 'dedupe_key' => $dedupeKey],
            [
                'category' => 'social',
                'title' => $title,
                'subtitle' => $identity['displayName'],
                'due_text' => 'Now',
                'payload' => [
                    'kind' => 'friendship',
                    'friendship_id' => $friendship->id,
                    'profile_slug' => $identity['slug'],
                ],
                'unread' => true,
                'read_at' => null,
            ],
        );
    }
}
