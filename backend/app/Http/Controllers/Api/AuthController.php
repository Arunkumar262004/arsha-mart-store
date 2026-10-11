<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\StoreResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

        return $this->issueToken($user, $request->validated('device_name') ?? 'web');
    }

    /**
     * "Sign in with Google": exchange a Google ID token (the credential from
     * Google Identity Services) for a Sanctum API token. Existing users sign
     * in with their own role; a new email gets an admin account so visitors
     * can explore the whole product.
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $clientId = config('services.google.client_id');
        abort_if(blank($clientId), 503, 'Google sign-in is not configured.');

        // Google checks the signature and expiry; we check it was issued to this app.
        $claims = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $data['credential']]);

        if (! $claims->ok()
            || $claims->json('aud') !== $clientId
            || ! in_array($claims->json('iss'), ['accounts.google.com', 'https://accounts.google.com'], true)
            || $claims->json('email_verified') !== 'true') {
            throw ValidationException::withMessages(['email' => 'Google sign-in failed. Please try again.']);
        }

        $email = mb_strtolower($claims->json('email'));
        $user = User::with('role.grants')->where('email', $email)->first()
            ?? $this->createGoogleAdmin($email, $claims->json('name'));

        return $this->issueToken($user, $data['device_name'] ?? 'web');
    }

    /**
     * A new admin for a first-time Google user. The random password is never
     * shown; they can set one later from "Change password".
     */
    private function createGoogleAdmin(string $email, ?string $name): User
    {
        $role = Role::where('is_admin', true)->first();
        abort_if($role === null, 503, 'No admin role is set up.');

        $user = User::create([
            'name' => $name ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Str::password(32),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->load('role.grants');
    }

    private function issueToken(User $user, string $deviceName): JsonResponse
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Contact your admin.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($deviceName)->plainTextToken;

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
     * The user, their permissions, the stores they may switch to and whether
     * they may pick "All stores" (users tied to one store cannot).
     *
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        $user->loadMissing('store');

        return [
            'user' => UserResource::make($user),
            'permissions' => $user->permissions(),
            'stores' => StoreResource::collection($user->accessibleStores()),
            'all_stores' => $user->worksInAllStores(),
        ];
    }
}
