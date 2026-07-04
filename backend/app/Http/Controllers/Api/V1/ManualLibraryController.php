<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Services\PlaybackLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualLibraryController extends Controller
{
    public function watchMovie(Request $request, Movie $movie, PlaybackLibraryService $library): JsonResponse
    {
        $data = $request->validate([
            'watched_at' => ['nullable', 'date'],
            'runtime' => ['nullable', 'integer', 'min:0'],
        ]);

        $watch = $library->manuallyTrackMovie($request->user(), $movie, $data);

        return response()->json([
            'watch' => [
                'id' => $watch->id,
                'movie_id' => $watch->movie_id,
                'watched_at' => $watch->watched_at?->toIso8601String(),
                'runtime' => $watch->runtime,
                'source' => $watch->source,
            ],
        ], 201);
    }
}
