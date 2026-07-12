<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UserAvatarService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class ProfileAvatarController extends Controller
{
    public function store(Request $request, UserAvatarService $avatars, UserProfileService $profiles): JsonResponse
    {
        $data = $request->validate([
            'avatar' => [
                'required',
                File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024),
            ],
        ]);

        $avatars->store($request->user(), $data['avatar']);

        return response()->json($profiles->ownPayload($request->user()->refresh()), 201);
    }

    public function destroy(Request $request, UserAvatarService $avatars, UserProfileService $profiles): JsonResponse
    {
        $avatars->remove($request->user());

        return response()->json($profiles->ownPayload($request->user()->refresh()));
    }
}
