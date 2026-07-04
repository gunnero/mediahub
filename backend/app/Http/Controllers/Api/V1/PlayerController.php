<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlaybackSession;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Services\PlaybackLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function sources(Request $request, PlaybackLibraryService $player): JsonResponse
    {
        return response()->json([
            'sources' => $player->sourcesFor($request->user()),
        ]);
    }

    public function play(Request $request, PlaybackSourceItem $item, PlaybackLibraryService $player): JsonResponse
    {
        $session = $player->startPlayback($request->user(), $item);

        return response()->json([
            'session' => [
                'id' => $session->id,
                'sourceItemId' => $session->playback_source_item_id,
                'status' => $session->status,
                'startedAt' => $session->started_at?->toIso8601String(),
            ],
            'playbackUrl' => $item->stream_url,
        ], 201);
    }

    public function link(Request $request, PlaybackSourceItem $item, PlaybackLibraryService $player): JsonResponse
    {
        $data = $request->validate([
            'movie_id' => ['nullable', 'integer'],
            'show_id' => ['nullable', 'integer'],
            'episode_id' => ['nullable', 'integer'],
        ]);

        $link = $player->link($request->user(), $item, $data);

        return response()->json([
            'link' => [
                'id' => $link->id,
                'source_item_id' => $link->playback_source_item_id,
                'movie_id' => $link->movie_id,
                'show_id' => $link->show_id,
                'episode_id' => $link->episode_id,
                'linked_at' => $link->linked_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function updateSession(Request $request, PlaybackSession $session, PlaybackLibraryService $player): JsonResponse
    {
        $data = $request->validate([
            'position_seconds' => ['required', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'completed' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);

        $session = $player->updateSession($request->user(), $session, $data);

        return response()->json([
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'positionSeconds' => $session->last_position_seconds,
                'durationSeconds' => $session->duration_seconds,
                'endedAt' => $session->ended_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroySource(Request $request, PlaybackSource $source, PlaybackLibraryService $player): JsonResponse
    {
        $player->deleteSource($request->user(), $source);

        return response()->json(null, 204);
    }
}
