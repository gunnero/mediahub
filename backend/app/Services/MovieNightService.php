<?php

namespace App\Services;

use App\Models\EpisodeWatch;
use App\Models\ExperienceSetting;
use App\Models\Friendship;
use App\Models\MovieWatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MovieNightService
{
    public function friends(User $user): array
    {
        return Friendship::forUser($user)->accepted()->get()->map(fn ($friend) => $friend->requester_user_id === $user->id ? $friend->addressee_user_id : $friend->requester_user_id)->all();
    }

    public function access(User $user, int $id)
    {
        $room = DB::table('movie_nights')->find($id);
        abort_unless($room, 404);
        if ($room->user_id === $user->id) {
            return $room;
        }
        abort_unless(in_array($room->user_id, $this->friends($user), true) && DB::table('movie_night_members')->where('movie_night_id', $id)->where('user_id', $user->id)->where('accepted', true)->exists(), 404);

        return $room;
    }

    public function payload(User $user, $room): array
    {
        $blocked = Friendship::forUser($user)->where('status', 'blocked')->get()->map(fn ($friend) => $friend->requester_user_id === $user->id ? $friend->addressee_user_id : $friend->requester_user_id)->all();
        $owner = User::findOrFail($room->user_id);
        $friends = $this->friends($owner);
        $members = DB::table('movie_night_members')->where('movie_night_id', $room->id)->whereIn('user_id', $friends)->whereNotIn('user_id', $blocked)->get();
        $active = [...$members->where('accepted', true)->pluck('user_id')->all(), $room->user_id];
        $titles = DB::table('movie_night_titles')->where('movie_night_id', $room->id)->whereIn('user_id', $active)->get()->map(function ($title) use ($user, $active, $room) {
            $voters = DB::table('movie_night_votes')->where('movie_night_title_id', $title->id)->whereIn('user_id', $active)->pluck('user_id');

            return ['id' => $title->id, 'title' => $title->title, 'poster' => $title->poster, 'year' => $title->year, 'votes' => $voters->count(), 'myVote' => $voters->contains($user->id), 'canRemove' => $title->user_id === $user->id || $room->user_id === $user->id];
        })->sortByDesc('votes')->values()->all();

        return ['id' => $room->id, 'name' => $room->name, 'owner' => $room->user_id === $user->id,
            'members' => $members->map(fn ($member) => ['id' => $member->id, 'name' => User::find($member->user_id)?->display_name ?: User::find($member->user_id)?->name, 'accepted' => (bool) $member->accepted])->all(), 'titles' => $titles];
    }

    public function feed(User $user): array
    {
        $friends = $this->friends($user);
        $settings = ExperienceSetting::whereIn('user_id', $friends)->where('share_activity', true)->get()->keyBy('user_id');
        $result = collect();
        foreach ([MovieWatch::class => 'movie', EpisodeWatch::class => 'episode'] as $model => $kind) {
            $model::whereIn('user_id', $settings->keys())->with([$kind, 'user'])->watched()->latest('watched_at')->limit(100)->get()->each(function ($watch) use ($result, $settings, $kind): void {
                if (! $settings[$watch->user_id]->activity_since || $watch->watched_at->lt($settings[$watch->user_id]->activity_since)) {
                    return;
                }
                $media = $watch->$kind;
                if (! $media || $media->user_id !== $watch->user_id || ($kind === 'episode' && $media->show?->user_id !== $watch->user_id)) {
                    return;
                }
                $result->push(['id' => $kind.'-'.$watch->id, 'name' => $watch->user->display_name ?: $watch->user->name,
                    'title' => $kind === 'episode' ? ($media->show?->title ?: 'A show').' · '.sprintf('S%02dE%02d', $media->season_number, $media->episode_number) : $media->title,
                    'watchedAt' => $watch->watched_at->toIso8601String()]);
            });
        }

        return $result->sortByDesc('watchedAt')->take(50)->values()->all();
    }
}
