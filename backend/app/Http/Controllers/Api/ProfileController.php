<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Support\Avatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * Update the signed-in user's own name and email. The email is the
     * login, so changing it needs the current password. The role stays
     * with the admin.
     */
    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $emailChanges = mb_strtolower(trim((string) $request->input('email'))) !== $user->email;

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => [Rule::requiredIf($emailChanges), 'nullable', 'string', 'current_password:sanctum'],
        ], [
            'email.unique' => 'Another employee already uses this email.',
            'current_password.required' => 'Enter your current password to change your email.',
        ]);

        $user->update(['name' => trim($validated['name']), 'email' => $validated['email']]);

        return UserResource::make($user->load('role'));
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
