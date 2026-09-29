<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Built-in Admin plus two example roles an admin can edit, and one
     * demo login per role (password: password123).
     */
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'Admin'], ['description' => 'Full access, including settings']);
        $admin->forceFill(['is_admin' => true])->save();

        $manager = Role::firstOrCreate(['name' => 'Store Manager'], ['description' => 'Runs the store: billing, orders and inventory']);
        $manager->syncPermissions(array_keys(config('permissions')));

        $cashier = Role::firstOrCreate(['name' => 'Cashier'], ['description' => 'Counter staff: billing and order lookup']);
        $cashier->syncPermissions(['dashboard.view', 'billing.create', 'orders.view']);

        foreach ([
            ['Senthil Kumar', 'admin@store.com', $admin],
            ['Meenakshi Sundaram', 'manager@store.com', $manager],
            ['Karthik Raja', 'cashier@store.com', $cashier],
        ] as [$name, $email, $role]) {
            User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password123', 'role_id' => $role->id, 'is_active' => true],
            );
        }
    }
}
