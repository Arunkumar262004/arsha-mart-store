<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Exchange credentials for a Sanctum API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::with('role.grants')->where('email', mb_strtolower($request->validated('email')))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Contact your admin.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($request->validated('device_name') ?? 'web')->plainTextToken;

        return response()->json(['token' => $token, ...$this->profile($user)]);
    }

    /**
     * The signed-in user with their role and effective permissions; the
     * frontend uses the permissions to decide which menus to show.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()->load('role.grants')));
    }

    /**
     * Revoke only the token used for this request (other devices stay signed in).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * @return array{user: UserResource, permissions: list<string>}
     */
    private function profile(User $user): array
    {
        return [
            'user' => UserResource::make($user),
            'permissions' => $user->permissions(),
        ];
    }
}
