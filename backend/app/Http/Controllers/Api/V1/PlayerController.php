<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlaybackSession;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Services\PlaybackLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlayerController extends Controller
{
    public function sources(Request $request, PlaybackLibraryService $player): JsonResponse
    {
        return response()->json([
            'sources' => $player->sourcesFor($request->user()),
        ]);
    }

    public function storeSource(Request $request, PlaybackLibraryService $player): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider_type' => ['required', 'string', Rule::in(['manual', 'plex', 'jellyfin', 'emby', 'smb', 'nas', 'local'])],
            'legal_confirmed' => ['accepted'],
        ]);

        $source = $player->createSource($request->user(), $data);

        return response()->json([
            'source' => $player->sourceSummary($source->loadCount('items')),
        ], 201);
    }

    public function updateSource(Request $request, PlaybackSource $source, PlaybackLibraryService $player): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'disabled'])],
        ]);

        $source = $player->updateSourceStatus($request->user(), $source, $data);

        return response()->json([
            'source' => $player->sourceSummary($source),
        ]);
    }

    public function storeItem(Request $request, PlaybackSource $source, PlaybackLibraryService $player): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::in(['movie', 'show', 'episode'])],
            'stream_url' => ['required', 'url', 'max:2048'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ]);

        $item = $player->createManualItem($request->user(), $source, $data);

        return response()->json([
            'item' => $player->sourceItemsForUser($request->user(), ['source_id' => $source->id, 'q' => $item->title])[0] ?? [
                'id' => $item->id,
                'title' => $item->title,
                'kind' => $item->kind,
                'linked' => false,
            ],
        ], 201);
    }

    public function items(Request $request, PlaybackLibraryService $player): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'source_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(['available', 'unavailable'])],
            'linked' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'items' => $player->sourceItemsForUser($request->user(), $data),
        ]);
    }

    public function linkTargets(Request $request, PlaybackLibraryService $player): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', Rule::in(['movie', 'show', 'episode'])],
        ]);

        return response()->json([
            'targets' => $player->linkTargetsFor($request->user(), $data),
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
            'confirm' => ['accepted'],
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

    public function unlink(Request $request, PlaybackSourceItem $item, PlaybackLibraryService $player): JsonResponse
    {
        $player->unlink($request->user(), $item);

        return response()->json(null, 204);
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
