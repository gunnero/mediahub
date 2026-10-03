<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DiscoveryService;
use App\Services\MediaDetailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DiscoveryController extends Controller
{
    public function curated(Request $request, DiscoveryService $discovery): JsonResponse
    {
        $data = $request->validate(['type' => 'nullable|in:all,movie,show', 'page' => 'nullable|integer|min:1|max:500',
            'category' => 'nullable|in:recommended,trending,popular,now_playing,upcoming,top_rated',
            'genre' => 'nullable|string|max:40', 'year' => 'nullable|integer|min:1888|max:2100',
            'language' => 'nullable|regex:/^[a-z]{2}$/', 'max_runtime' => 'nullable|integer|min:1|max:1440',
            'min_rating' => 'nullable|numeric|min:0|max:10', 'hide_watched' => 'nullable|boolean']);

        return response()->json($discovery->curated($request->user(), $data));
    }

    public function dismiss(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:movie,show', 'tmdb_id' => 'required|integer|min:1']);
        DB::table('discovery_dismissals')->updateOrInsert([
            'user_id' => $request->user()->id, 'media_type' => $data['type'], 'tmdb_id' => $data['tmdb_id'],
        ], ['created_at' => now(), 'updated_at' => now()]);

        return response()->json(['dismissed' => true]);
    }

    public function resetDismissals(Request $request)
    {
        DB::table('discovery_dismissals')->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    public function detail(Request $request, string $type, int $tmdbId, DiscoveryService $discovery): JsonResponse
    {
        abort_unless(in_array($type, ['movie', 'show'], true), 404);

        return response()->json($discovery->detail($request->user(), $type, $tmdbId));
    }

    public function browse(Request $request, DiscoveryService $discovery): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', Rule::in(['trending', 'popular', 'now_playing', 'upcoming', 'top_rated'])],
            'type' => ['nullable', 'string', Rule::in(['movie', 'show', 'all'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json($discovery->browse(
            $request->user(),
            $data['category'] ?? 'trending',
            $data['type'] ?? 'all',
            (int) ($data['page'] ?? 1),
        ));
    }

    public function search(Request $request, DiscoveryService $discovery): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['nullable', 'string', Rule::in(['movie', 'show', 'all'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'year' => ['nullable', 'integer', 'min:1888', 'max:2100'],
        ]);

        return response()->json($discovery->search(
            $request->user(),
            trim($data['query']),
            $data['type'] ?? 'all',
            (int) ($data['page'] ?? 1),
            isset($data['year']) ? (int) $data['year'] : null,
        ));
    }

    public function addMovie(Request $request, int $tmdbId, DiscoveryService $discovery, MediaDetailService $details): JsonResponse
    {
        $data = $request->validate(['action' => ['nullable', 'string', Rule::in(['library', 'watchlist', 'watched'])]]);
        $movie = $discovery->addMovie($request->user(), $tmdbId, $data['action'] ?? 'library');

        return response()->json(['item' => $details->movie($request->user(), $movie)], 201);
    }

    public function addShow(Request $request, int $tmdbId, DiscoveryService $discovery, MediaDetailService $details): JsonResponse
    {
        $data = $request->validate(['action' => ['nullable', 'string', Rule::in(['library', 'watchlist', 'watched'])]]);
        $show = $discovery->addShow($request->user(), $tmdbId, $data['action'] ?? 'library');

        return response()->json(['item' => $details->show($request->user(), $show)], 201);
    }
}
