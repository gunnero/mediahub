<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MediaEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaEventController extends Controller
{
    public function index(Request $request, MediaEventService $events): JsonResponse
    {
        $filters = $request->validate([
            'event_type' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:32'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'events' => $events->timeline($request->user(), $filters),
        ]);
    }

    public function recent(Request $request, MediaEventService $events): JsonResponse
    {
        return response()->json([
            'events' => $events->recent($request->user()),
        ]);
    }
}
