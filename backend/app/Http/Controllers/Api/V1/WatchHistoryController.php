<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WatchHistoryEditor;
use Illuminate\Http\Request;

class WatchHistoryController extends Controller
{
    public function index(Request $request, string $type, int $media, WatchHistoryEditor $editor)
    {
        $query = $editor->query($request->user(), $type)->where($type.'_id', $media)->watched();
        $watches = $query->latest('watched_at')->latest('id')->paginate(30);

        return response()->json(['items' => collect($watches->items())->map(fn ($watch) => [
            'id' => $watch->id, 'watchedAt' => $watch->watched_at->toIso8601String(),
            'runtime' => $watch->runtime, 'source' => $watch->source, 'count' => $watch->watch_count ?? 1,
        ]), 'page' => $watches->currentPage(), 'pages' => $watches->lastPage(), 'total' => $watches->total()]);
    }

    public function update(Request $request, string $type, int $watch, WatchHistoryEditor $editor)
    {
        $data = $request->validate(['watched_at' => ['required', 'date', 'before_or_equal:now'], 'runtime' => ['sometimes', 'integer', 'min:0', 'max:1440']]);
        $revision = $editor->change($request->user(), $type, $watch, $data);

        return response()->json(['undoId' => $revision->id]);
    }

    public function destroy(Request $request, string $type, int $watch, WatchHistoryEditor $editor)
    {
        $revision = $editor->change($request->user(), $type, $watch, null);

        return response()->json(['undoId' => $revision->id]);
    }

    public function undo(Request $request, int $revision, WatchHistoryEditor $editor)
    {
        $editor->undo($request->user(), $revision);

        return response()->noContent();
    }
}
