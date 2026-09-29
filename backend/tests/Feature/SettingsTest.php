<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signIn();
    }

    public function testAdminCreatesARoleThenAUserWithIt(): void
    {
        $roleId = $this->postJson('/api/roles', [
            'name' => 'Stock Keeper',
            'permissions' => ['products.view', 'stock.adjust'],
        ])->assertCreated()->assertJsonPath('data.permissions', ['products.view', 'stock.adjust'])->json('data.id');

        $this->postJson('/api/users', [
            'name' => 'Sam', 'email' => 'SAM@store.test', 'role_id' => $roleId,
            'password' => 'keeper123', 'password_confirmation' => 'keeper123',
        ])->assertCreated()->assertJsonPath('data.role.name', 'Stock Keeper');

        $this->postJson('/api/login', ['email' => 'sam@store.test', 'password' => 'keeper123'])
            ->assertOk()
            ->assertJsonPath('permissions', ['products.view', 'stock.adjust']);
    }

    public function testRolePermissionsAreValidatedAndEditable(): void
    {
        $this->postJson('/api/roles', ['name' => 'Bad', 'permissions' => ['settings.manage']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');

        $role = $this->makeRole(['orders.view']);

        $this->putJson("/api/roles/{$role->id}", ['name' => 'Renamed', 'permissions' => ['dashboard.view']])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.permissions', ['dashboard.view']);
    }

    public function testAdminRoleIsProtectedAndRolesInUseCannotBeDeleted(): void
    {
        $adminRole = Role::where('is_admin', true)->first();

        $this->putJson("/api/roles/{$adminRole->id}", ['name' => 'Admin', 'permissions' => []])->assertUnprocessable();
        $this->deleteJson("/api/roles/{$adminRole->id}")->assertUnprocessable();

        $used = $this->makeRole(['orders.view']);
        User::factory()->withRole($used)->create();
        $this->deleteJson("/api/roles/{$used->id}")->assertUnprocessable();

        $unused = $this->makeRole([]);
        $this->deleteJson("/api/roles/{$unused->id}")->assertOk();
        $this->assertModelMissing($unused);
    }

    public function testAdminResetsAPasswordAndTheUserIsSignedOut(): void
    {
        $user = User::factory()->withRole($this->makeRole(['orders.view']))->create();
        $user->createToken('web');

        $this->putJson("/api/users/{$user->id}/password", ['password' => 'fresh123', 'password_confirmation' => 'fresh123'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'fresh123'])->assertOk();
    }

    public function testWeakPasswordsAreRejected(): void
    {
        $user = User::factory()->withRole($this->makeRole([]))->create();

        $this->putJson("/api/users/{$user->id}/password", ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function testDeactivatingAUserRevokesTheirSessions(): void
    {
        $role = $this->makeRole(['orders.view']);
        $user = User::factory()->withRole($role)->create();
        $user->createToken('web');

        $this->putJson("/api/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role_id' => $role->id, 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function testAdminCannotLockThemselvesOut(): void
    {
        $other = $this->makeRole([]);

        $this->putJson("/api/users/{$this->admin->id}", [
            'name' => 'Me', 'email' => $this->admin->email, 'role_id' => $other->id, 'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['is_active', 'role_id']);

        $this->deleteJson("/api/users/{$this->admin->id}")->assertUnprocessable();
    }
}
