<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MediaMetadataService;
use App\Services\MovieNightService;
use App\Services\PersonalLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovieNightController extends Controller
{
    public function index(Request $request, MovieNightService $service)
    {
        $user = $request->user();
        $friends = $service->friends($user);
        $rooms = DB::table('movie_nights')->where(function ($q) use ($user, $friends) {
            $q->where('user_id', $user->id)->orWhere(fn ($q) => $q->whereIn('user_id', $friends)->whereIn('id', DB::table('movie_night_members')->where('user_id', $user->id)->where('accepted', true)->select('movie_night_id')));
        })->latest('id')->get();
        $invites = DB::table('movie_night_members')->join('movie_nights', 'movie_nights.id', '=', 'movie_night_members.movie_night_id')->where('movie_night_members.user_id', $user->id)->where('accepted', false)->whereIn('movie_nights.user_id', $friends)->get(['movie_nights.id', 'movie_nights.name']);
        $recommendations = DB::table('friend_recommendations')->where('recipient_id', $user->id)->whereIn('user_id', $friends)->latest('id')->limit(50)->get()->map(fn ($row) => ['id' => $row->id, 'from' => User::find($row->user_id)?->display_name ?: User::find($row->user_id)?->name,
            'title' => $row->title, 'message' => $row->message, 'spoiler' => (bool) $row->spoiler]);

        return response()->json(['rooms' => $rooms->map(fn ($room) => $service->payload($user, $room)), 'invites' => $invites,
            'friends' => User::whereIn('id', $friends)->get()->map(fn ($friend) => ['slug' => $friend->profile_slug, 'name' => $friend->display_name ?: $friend->name]), 'activity' => $service->feed($user), 'recommendations' => $recommendations]);
    }

    public function create(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $id = DB::table('movie_nights')->insertGetId(['user_id' => $request->user()->id, 'name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id], 201);
    }

    public function invite(Request $request, int $room, MovieNightService $service)
    {
        $record = $service->access($request->user(), $room);
        abort_unless($record->user_id === $request->user()->id, 403);
        $data = $request->validate(['slug' => 'required|string|max:120']);
        $friend = User::where('profile_slug', $data['slug'])->whereIn('id', $service->friends($request->user()))->firstOrFail();
        DB::table('movie_night_members')->insertOrIgnore(['movie_night_id' => $room, 'user_id' => $friend->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }

    public function join(Request $request, int $room, MovieNightService $service)
    {
        $record = DB::table('movie_nights')->whereIn('user_id', $service->friends($request->user()))->find($room);
        abort_unless($record, 404);
        $member = DB::table('movie_night_members')->where('movie_night_id', $room)->where('user_id', $request->user()->id)->first();
        abort_unless($member, 404);
        DB::table('movie_night_members')->where('id', $member->id)->update(['accepted' => true, 'updated_at' => now()]);

        return response()->noContent();
    }

    public function leave(Request $request, int $room)
    {
        $record = DB::table('movie_nights')->find($room);
        abort_unless($record, 404);
        if ($record->user_id === $request->user()->id) {
            DB::table('movie_nights')->where('id', $room)->delete();
        } else {
            DB::table('movie_night_members')->where('movie_night_id', $room)->where('user_id', $request->user()->id)->delete();
        }

        return response()->noContent();
    }

    public function add(Request $request, int $room, MovieNightService $service, PersonalLibraryService $library, MediaMetadataService $metadata)
    {
        $service->access($request->user(), $room);
        $data = $request->validate(['type' => 'required|in:movie,show', 'media_id' => 'required|integer|min:1']);
        $media = $library->owned($request->user(), $data['type'], $data['media_id']);
        DB::table('movie_night_titles')->updateOrInsert(['movie_night_id' => $room, 'user_id' => $request->user()->id, 'media_type' => $data['type'], 'title' => $media->title],
            ['tmdb_id' => $media->tmdb_id, 'year' => ($media->release_date ?? $media->first_air_date)?->format('Y'), 'poster' => $metadata->imageUrl($media->poster_path) ?: '', 'created_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }

    public function vote(Request $request, int $title, MovieNightService $service)
    {
        $candidate = DB::table('movie_night_titles')->find($title);
        abort_unless($candidate, 404);
        $service->access($request->user(), $candidate->movie_night_id);
        $data = $request->validate(['vote' => 'required|boolean']);
        $key = ['movie_night_title_id' => $title, 'user_id' => $request->user()->id];
        if ($data['vote']) {
            DB::table('movie_night_votes')->updateOrInsert($key, ['created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('movie_night_votes')->where($key)->delete();
        }

        return response()->noContent();
    }

    public function removeTitle(Request $request, int $title, MovieNightService $service)
    {
        $candidate = DB::table('movie_night_titles')->find($title);
        abort_unless($candidate, 404);
        $room = $service->access($request->user(), $candidate->movie_night_id);
        abort_unless($room->user_id === $request->user()->id || $candidate->user_id === $request->user()->id, 403);
        DB::table('movie_night_titles')->where('id', $title)->delete();

        return response()->noContent();
    }

    public function recommend(Request $request, MovieNightService $service, PersonalLibraryService $library)
    {
        $data = $request->validate(['slug' => 'required|string|max:120', 'type' => 'required|in:movie,show', 'media_id' => 'required|integer|min:1', 'message' => 'nullable|string|max:1000', 'spoiler' => 'sometimes|boolean']);
        $friend = User::where('profile_slug', $data['slug'])->whereIn('id', $service->friends($request->user()))->firstOrFail();
        $media = $library->owned($request->user(), $data['type'], $data['media_id']);
        DB::table('friend_recommendations')->insert(['user_id' => $request->user()->id, 'recipient_id' => $friend->id, 'title' => $media->title, 'media_type' => $data['type'], 'tmdb_id' => $media->tmdb_id,
            'message' => $data['message'] ?? '', 'spoiler' => $data['spoiler'] ?? false, 'created_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }
}
