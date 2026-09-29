<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Support\Avatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Change the signed-in user's own password. Other sessions are signed
     * out; the current one stays active.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update(['password' => $request->validated('password')]);
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Set the signed-in user's profile photo (shown in the header).
     */
    public function updateAvatar(Request $request): UserResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'avatar.max' => 'The photo must be 5 MB or smaller.',
        ]);

        $user = $request->user();
        $user->forceFill(['avatar' => Avatar::fromUpload($request->file('avatar'))])->save();

        return UserResource::make($user->load('role'));
    }

    public function deleteAvatar(Request $request): UserResource
    {
        $user = $request->user();
        $user->forceFill(['avatar' => null])->save();

        return UserResource::make($user->load('role'));
    }
}
