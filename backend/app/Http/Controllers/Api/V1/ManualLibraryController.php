<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Show;
use App\Services\MediaAnnotationService;
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

    public function rateMovie(Request $request, Movie $movie, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->ratingResponse($request, $annotations, 'movie', $movie->id);
    }

    public function rateShow(Request $request, Show $show, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->ratingResponse($request, $annotations, 'show', $show->id);
    }

    public function rateEpisode(Request $request, Episode $episode, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->ratingResponse($request, $annotations, 'episode', $episode->id);
    }

    public function noteMovie(Request $request, Movie $movie, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->noteResponse($request, $annotations, 'movie', $movie->id);
    }

    public function noteShow(Request $request, Show $show, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->noteResponse($request, $annotations, 'show', $show->id);
    }

    public function noteEpisode(Request $request, Episode $episode, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->noteResponse($request, $annotations, 'episode', $episode->id);
    }

    private function ratingResponse(Request $request, MediaAnnotationService $annotations, string $mediaType, int $mediaId): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $rating = $annotations->rate($request->user(), $mediaType, $mediaId, (int) $data['rating']);

        return response()->json([
            'rating' => [
                'id' => $rating->id,
                'media_type' => $rating->media_type,
                'media_id' => $rating->media_id,
                'rating' => $rating->rating,
            ],
        ]);
    }

    private function noteResponse(Request $request, MediaAnnotationService $annotations, string $mediaType, int $mediaId): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $annotations->addNote($request->user(), $mediaType, $mediaId, trim($data['body']));

        return response()->json([
            'note' => [
                'id' => $note->id,
                'media_type' => $note->media_type,
                'media_id' => $note->media_id,
                'body' => $note->body,
            ],
        ], 201);
    }
}
