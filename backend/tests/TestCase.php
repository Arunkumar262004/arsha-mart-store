<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a user. With no permissions given the user is an
     * admin; otherwise they get a custom role holding exactly those keys.
     *
     * @param  list<string>|null  $permissions
     */
    protected function signIn(?array $permissions = null, array $attributes = []): User
    {
        $user = User::factory()->withRole($this->makeRole($permissions))->create($attributes);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  list<string>|null  $permissions  null = the Admin role
     */
    protected function makeRole(?array $permissions = null): Role
    {
        if ($permissions === null) {
            return Role::where('is_admin', true)->first()
                ?? tap(Role::create(['name' => 'Admin']), fn (Role $role) => $role->forceFill(['is_admin' => true])->save());
        }

        $role = Role::create(['name' => 'Role '.uniqid()]);
        $role->syncPermissions($permissions);

        return $role;
    }
}
