<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
