<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProfileVisibility;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserProfileService;
use App\Support\CountryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function own(Request $request, UserProfileService $profiles): JsonResponse
    {
        return response()->json($profiles->ownPayload($request->user()));
    }

    public function options(Request $request, UserProfileService $profiles): JsonResponse
    {
        return response()->json($profiles->profileOptions($request->user()));
    }

    public function update(Request $request, UserProfileService $profiles): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'username' => [
                'sometimes', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'profile_slug' => [
                'sometimes', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('users', 'profile_slug')->ignore($user->id),
                function (string $attribute, mixed $value, \Closure $fail) use ($profiles): void {
                    if ($profiles->isReservedSlug((string) $value)) {
                        $fail('This profile address is reserved.');
                    }
                },
            ],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(CountryCatalog::codes())],
            'favorite_genres' => ['sometimes', 'array', 'max:12'],
            'favorite_genres.*' => ['string', 'max:40'],
            'favorite_movie_ids' => ['sometimes', 'array', 'max:20'],
            'favorite_movie_ids.*' => ['integer'],
            'favorite_show_ids' => ['sometimes', 'array', 'max:20'],
            'favorite_show_ids.*' => ['integer'],
            'featured_list_ids' => ['sometimes', 'array', 'max:10'],
            'featured_list_ids.*' => ['integer'],
        ]);

        $profiles->updateIdentity($user, $data);

        return response()->json($profiles->ownPayload($user->refresh()));
    }

    public function privacy(Request $request, UserProfileService $profiles): JsonResponse
    {
        $data = $request->validate([
            'public_profile_enabled' => ['sometimes', 'boolean'],
            'show_avatar' => ['sometimes', 'boolean'],
            'profile_visibility' => ['sometimes', Rule::enum(ProfileVisibility::class)],
            'show_statistics' => ['sometimes', 'boolean'],
            'show_favorite_movies' => ['sometimes', 'boolean'],
            'show_favorite_shows' => ['sometimes', 'boolean'],
            'show_public_lists' => ['sometimes', 'boolean'],
            'show_recent_activity' => ['sometimes', 'boolean'],
            'allow_friend_requests' => ['sometimes', 'boolean'],
            'allow_profile_sharing' => ['sometimes', 'boolean'],
            'allow_search_discovery' => ['sometimes', 'boolean'],
        ]);

        $profiles->updatePrivacy($request->user(), $data);

        return response()->json($profiles->ownPayload($request->user()->refresh()));
    }

    public function preview(Request $request, UserProfileService $profiles): JsonResponse
    {
        return response()->json($profiles->publicProfile($request->user(), $request->user(), true));
    }

    public function show(Request $request, User $user, UserProfileService $profiles): JsonResponse
    {
        return response()->json($profiles->publicProfile($user, $request->user()));
    }

    public function search(Request $request, UserProfileService $profiles): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'min:2', 'max:80']]);

        return response()->json(['profiles' => $profiles->search($request->user(), $data['query'])]);
    }
}
