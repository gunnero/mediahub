<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Note;
use App\Models\Show;
use App\Services\MediaAnnotationService;
use App\Services\MediaDetailService;
use App\Services\MediaEventService;
use App\Services\PlaybackLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualLibraryController extends Controller
{
    public function showMovie(Request $request, Movie $movie, MediaDetailService $details): JsonResponse
    {
        return response()->json([
            'item' => $details->movie($request->user(), $movie),
        ]);
    }

    public function showShow(Request $request, Show $show, MediaDetailService $details): JsonResponse
    {
        return response()->json([
            'item' => $details->show($request->user(), $show),
        ]);
    }

    public function showEpisode(Request $request, Episode $episode, MediaDetailService $details): JsonResponse
    {
        return response()->json([
            'item' => $details->episode($request->user(), $episode),
        ]);
    }

    public function watchMovie(Request $request, Movie $movie, PlaybackLibraryService $library): JsonResponse
    {
        $data = $request->validate([
            'watched_at' => ['nullable', 'date', 'before_or_equal:now'],
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

    public function watchEpisode(Request $request, Episode $episode, PlaybackLibraryService $library): JsonResponse
    {
        $data = $request->validate([
            'watched_at' => ['nullable', 'date', 'before_or_equal:now'],
            'runtime' => ['nullable', 'integer', 'min:0'],
        ]);

        $watch = $library->manuallyTrackEpisode($request->user(), $episode, $data);

        return response()->json([
            'watch' => [
                'id' => $watch->id,
                'show_id' => $watch->show_id,
                'episode_id' => $watch->episode_id,
                'watched_at' => $watch->watched_at?->toIso8601String(),
                'runtime' => $watch->runtime,
                'source' => $watch->source,
            ],
        ], 201);
    }

    public function unwatchMovie(Request $request, Movie $movie, PlaybackLibraryService $library): JsonResponse
    {
        $library->untrackManualMovie($request->user(), $movie);

        return response()->json(null, 204);
    }

    public function unwatchEpisode(Request $request, Episode $episode, PlaybackLibraryService $library): JsonResponse
    {
        $library->untrackManualEpisode($request->user(), $episode);

        return response()->json(null, 204);
    }

    public function watchlistMovie(Request $request, Movie $movie, MediaEventService $events): JsonResponse
    {
        $movie = Movie::forUser($request->user())->findOrFail($movie->id);
        $movie->forceFill(['is_to_watch' => true])->save();
        $events->record($request->user(), MediaEventType::WatchlistAdded, $movie, ['title' => $movie->title, 'media_type' => 'movie'], MediaEventSource::Manual);

        return response()->json(['watchlist' => true]);
    }

    public function removeMovieWatchlist(Request $request, Movie $movie, MediaEventService $events): JsonResponse
    {
        $movie = Movie::forUser($request->user())->findOrFail($movie->id);
        $movie->forceFill(['is_to_watch' => false])->save();
        $events->record($request->user(), MediaEventType::WatchlistRemoved, $movie, ['title' => $movie->title, 'media_type' => 'movie'], MediaEventSource::Manual);

        return response()->json(null, 204);
    }

    public function watchlistShow(Request $request, Show $show, MediaEventService $events): JsonResponse
    {
        $show = Show::forUser($request->user())->findOrFail($show->id);
        $show->forceFill(['followed' => true])->save();
        $events->record($request->user(), MediaEventType::WatchlistAdded, $show, ['title' => $show->title, 'media_type' => 'show'], MediaEventSource::Manual);

        return response()->json(['watchlist' => true]);
    }

    public function removeShowWatchlist(Request $request, Show $show, MediaEventService $events): JsonResponse
    {
        $show = Show::forUser($request->user())->findOrFail($show->id);
        $show->forceFill(['followed' => false])->save();
        $events->record($request->user(), MediaEventType::WatchlistRemoved, $show, ['title' => $show->title, 'media_type' => 'show'], MediaEventSource::Manual);

        return response()->json(null, 204);
    }

    public function watchSeason(Request $request, Show $show, int $season, PlaybackLibraryService $library): JsonResponse
    {
        return response()->json($library->manuallyTrackSeason($request->user(), $show, $season));
    }

    public function unwatchSeason(Request $request, Show $show, int $season, PlaybackLibraryService $library): JsonResponse
    {
        return response()->json($library->untrackManualSeason($request->user(), $show, $season));
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

    public function clearMovieRating(Request $request, Movie $movie, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->clearRatingResponse($request, $annotations, 'movie', $movie->id);
    }

    public function clearShowRating(Request $request, Show $show, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->clearRatingResponse($request, $annotations, 'show', $show->id);
    }

    public function clearEpisodeRating(Request $request, Episode $episode, MediaAnnotationService $annotations): JsonResponse
    {
        return $this->clearRatingResponse($request, $annotations, 'episode', $episode->id);
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

    public function updateNote(Request $request, Note $note, MediaAnnotationService $annotations): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $annotations->updateNote($request->user(), $note, trim($data['body']));

        return response()->json([
            'note' => $this->notePayload($note),
        ]);
    }

    public function deleteNote(Request $request, Note $note, MediaAnnotationService $annotations): JsonResponse
    {
        $annotations->deleteNote($request->user(), $note);

        return response()->json(null, 204);
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

    private function clearRatingResponse(Request $request, MediaAnnotationService $annotations, string $mediaType, int $mediaId): JsonResponse
    {
        $annotations->clearRating($request->user(), $mediaType, $mediaId);

        return response()->json(null, 204);
    }

    private function noteResponse(Request $request, MediaAnnotationService $annotations, string $mediaType, int $mediaId): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $annotations->addNote($request->user(), $mediaType, $mediaId, trim($data['body']));

        return response()->json([
            'note' => $this->notePayload($note),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function notePayload(Note $note): array
    {
        return [
            'id' => $note->id,
            'media_type' => $note->media_type,
            'media_id' => $note->media_id,
            'body' => $note->body,
        ];
    }
}
