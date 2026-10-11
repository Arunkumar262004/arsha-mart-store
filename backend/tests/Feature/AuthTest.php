<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->withRole($this->makeRole(['billing.create']))->create([
            'email' => 'cashier@store.test',
            'password' => 'secret123',
            ...$attributes,
        ]);
    }

    public function testLoginReturnsATokenAndPermissions(): void
    {
        $this->user();

        $response = $this->postJson('/api/login', ['email' => 'Cashier@Store.test', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('user.email', 'cashier@store.test')
            ->assertJsonPath('permissions', ['billing.create']);

        $this->withToken($response->json('token'))->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'cashier@store.test');
    }

    public function testWrongPasswordAndInactiveAccountsAreRejected(): void
    {
        $this->user();
        $this->postJson('/api/login', ['email' => 'cashier@store.test', 'password' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        User::first()->update(['is_active' => false]);
        $this->postJson('/api/login', ['email' => 'cashier@store.test', 'password' => 'secret123'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'This account has been deactivated. Contact your admin.');
    }

    /** Claims the faked Google tokeninfo endpoint answers with. */
    private array $googleClaims = [];

    /** Fakes Google's tokeninfo endpoint answering with these ID-token claims. */
    private function fakeGoogle(array $claims = []): void
    {
        config(['services.google.client_id' => 'test-client.apps.googleusercontent.com']);

        $this->googleClaims = [
            'aud' => 'test-client.apps.googleusercontent.com',
            'iss' => 'https://accounts.google.com',
            'email' => 'owner@store.test',
            'email_verified' => 'true',
            ...$claims,
        ];

        // Registered once: later calls only swap the claims (the first matching fake wins).
        Http::fake(['oauth2.googleapis.com/*' => fn () => Http::response($this->googleClaims)]);
    }

    public function testAdminCanSignInWithGoogle(): void
    {
        $this->user(['email' => 'owner@store.test', 'role_id' => $this->makeRole()->id]);
        $this->fakeGoogle(['email' => 'Owner@Store.test']);

        $response = $this->postJson('/api/login/google', ['credential' => 'google-id-token'])
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@store.test');

        $this->withToken($response->json('token'))->getJson('/api/me')->assertOk();
        Http::assertSent(fn ($request) => $request['id_token'] === 'google-id-token');
    }

    public function testGoogleSignInRejectsNonAdminsUnknownEmailsAndForeignTokens(): void
    {
        $this->user(['email' => 'owner@store.test']);
        $this->fakeGoogle();
        $this->postJson('/api/login/google', ['credential' => 'token'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Google sign-in is only for admins. Use your email and password.');

        $this->fakeGoogle(['email' => 'stranger@gmail.com']);
        $this->postJson('/api/login/google', ['credential' => 'token'])->assertUnprocessable();

        User::first()->update(['role_id' => $this->makeRole()->id]);
        $this->fakeGoogle(['aud' => 'someone-elses-app.apps.googleusercontent.com']);
        $this->postJson('/api/login/google', ['credential' => 'token'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Google sign-in failed. Please try again.');

        $this->fakeGoogle();
        User::first()->update(['is_active' => false]);
        $this->postJson('/api/login/google', ['credential' => 'token'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This account has been deactivated. Contact your admin.');

        $this->assertSame(0, User::first()->tokens()->count());
    }

    public function testLogoutRevokesTheToken(): void
    {
        $user = $this->user();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function testApiRequiresAuthentication(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
        $this->postJson('/api/orders', [])->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function testLoginIsRateLimited(): void
    {
        $this->user();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', ['email' => 'cashier@store.test', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['email' => 'cashier@store.test', 'password' => 'secret123'])->assertTooManyRequests();
    }

    public function testUserCanChangeOwnPasswordAndOtherSessionsEnd(): void
    {
        $user = $this->user();
        $current = $user->createToken('this-device')->plainTextToken;
        $user->createToken('other-device');

        $this->withToken($current)->putJson('/api/me/password', [
            'current_password' => 'wrong',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->withToken($current)->putJson('/api/me/password', [
            'current_password' => 'secret123',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count(), 'Only the current session survives.');
        $this->postJson('/api/login', ['email' => 'cashier@store.test', 'password' => 'newpass123'])->assertOk();
    }
}
