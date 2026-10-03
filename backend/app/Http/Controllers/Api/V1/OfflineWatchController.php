<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\User;
use App\Services\PlaybackLibraryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineWatchController extends Controller
{
    public function __invoke(Request $request, PlaybackLibraryService $library)
    {
        $data = $request->validate(['entries' => 'required|array|min:1|max:100', 'entries.*.id' => 'required|uuid|distinct', 'entries.*.type' => 'required|in:movie,episode',
            'entries.*.media_id' => 'required|integer|min:1', 'entries.*.watched_at' => 'required|date|before_or_equal:now']);
        $accepted = DB::transaction(function () use ($request, $data, $library) {
            $user = $request->user();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $accepted = [];
            foreach ($data['entries'] as $entry) {
                $hash = hash('sha256', json_encode([$entry['type'], (int) $entry['media_id'], CarbonImmutable::parse($entry['watched_at'])->utc()->toIso8601String()]));
                $key = ['user_id' => $user->id, 'client_id' => $entry['id']];
                $receipt = DB::table('offline_watch_receipts')->where($key)->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'An offline entry ID was reused with different data.');
                } else {
                    $media = ($entry['type'] === 'movie' ? Movie::query() : Episode::query())->forUser($user)->findOrFail($entry['media_id']);
                    $watch = $entry['type'] === 'movie' ? $library->manuallyTrackMovie($user, $media, ['watched_at' => $entry['watched_at']]) : $library->manuallyTrackEpisode($user, $media, ['watched_at' => $entry['watched_at']]);
                    DB::table('offline_watch_receipts')->insert([...$key, 'request_hash' => $hash, 'media_type' => $entry['type'], 'watch_id' => $watch->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                $accepted[] = $entry['id'];
            }

            return $accepted;
        });

        return response()->json(['accepted' => $accepted]);
    }
}
