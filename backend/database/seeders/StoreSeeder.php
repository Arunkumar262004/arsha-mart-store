<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Seeder;

/**
 * A second branch with its own opening stock and a cashier who can only
 * work there, so the store switcher and stock transfers have something to show.
 */
class StoreSeeder extends Seeder
{
    public function run(StockService $stock): void
    {
        Store::main()->update(['code' => 'MAIN', 'state' => 'Tamil Nadu', 'state_code' => '33', 'city' => 'Chennai']);

        $branch = Store::updateOrCreate(['code' => 'ANN'], [
            'name' => 'Anna Nagar Branch',
            'city' => 'Chennai',
            'state' => 'Tamil Nadu',
            'state_code' => '33',
            'is_active' => true,
        ]);

        if (! StockMovement::where('store_id', $branch->id)->exists()) {
            foreach (Product::orderBy('id')->get() as $product) {
                $stock->adjust($product, 15, StockMovement::TYPE_INITIAL, null, 'Opening stock', $branch);
            }
        }

        User::updateOrCreate(
            ['email' => 'branch@store.com'],
            [
                'name' => 'Priya Venkatesh',
                'password' => 'password123',
                'role_id' => Role::where('name', 'Cashier')->value('id'),
                'store_id' => $branch->id,
                'is_active' => true,
            ],
        );
    }
}
