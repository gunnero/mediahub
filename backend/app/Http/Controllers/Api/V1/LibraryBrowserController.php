<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LibraryBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryBrowserController extends Controller
{
    public function movies(Request $request, LibraryBrowserService $library): JsonResponse
    {
        return response()->json($library->movies($request->user(), $request->query()));
    }

    public function shows(Request $request, LibraryBrowserService $library): JsonResponse
    {
        return response()->json($library->shows($request->user(), $request->query()));
    }

    public function continueWatching(Request $request, LibraryBrowserService $library): JsonResponse
    {
        return response()->json($library->continueWatching($request->user(), $request->query()));
    }

    public function history(Request $request, LibraryBrowserService $library): JsonResponse
    {
        return response()->json($library->history($request->user(), $request->query()));
    }

    public function search(Request $request, LibraryBrowserService $library): JsonResponse
    {
        return response()->json($library->search($request->user(), $request->query()));
    }
}
