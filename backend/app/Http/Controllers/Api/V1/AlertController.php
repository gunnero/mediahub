<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\NotificationPreference;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request, AlertService $alerts): JsonResponse
    {
        $alerts->syncForUser($request->user());
        $items = $alerts->visibleForUser($request->user(), 100);

        return response()->json([
            'alerts' => $items->map(fn (Alert $alert): array => $this->summary($alert))->all(),
            'unread' => $items->where('unread', true)->count(),
        ]);
    }

    public function read(Request $request, Alert $alert, AlertService $alerts): JsonResponse
    {
        $alert = $alerts->markRead($request->user(), $alert);

        return response()->json([
            'alert' => $this->summary($alert),
        ]);
    }

    public function readAll(Request $request, AlertService $alerts): JsonResponse
    {
        return response()->json([
            'updated' => $alerts->markAllRead($request->user()),
        ]);
    }

    public function preferences(Request $request, AlertService $alerts): JsonResponse
    {
        return response()->json(['preferences' => $this->preferenceSummary($alerts->preferences($request->user()))]);
    }

    public function updatePreferences(Request $request, AlertService $alerts): JsonResponse
    {
        $data = $request->validate([
            'new_episodes' => ['sometimes', 'boolean'],
            'movie_releases' => ['sometimes', 'boolean'],
            'reminders' => ['sometimes', 'boolean'],
            'in_app_enabled' => ['sometimes', 'boolean'],
            'email_enabled' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['preferences' => $this->preferenceSummary($alerts->updatePreferences($request->user(), $data))]);
    }

    private function summary(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'category' => $alert->category,
            'title' => $alert->title,
            'subtitle' => $alert->subtitle,
            'dueText' => $alert->due_text,
            'payload' => $alert->payload,
            'unread' => $alert->unread,
            'readAt' => $alert->read_at?->toIso8601String(),
            'createdAt' => $alert->created_at?->toIso8601String(),
        ];
    }

    private function preferenceSummary(NotificationPreference $preferences): array
    {
        return [
            'newEpisodes' => $preferences->new_episodes,
            'movieReleases' => $preferences->movie_releases,
            'reminders' => $preferences->reminders,
            'inAppEnabled' => $preferences->in_app_enabled,
            'emailEnabled' => $preferences->email_enabled,
        ];
    }
}
