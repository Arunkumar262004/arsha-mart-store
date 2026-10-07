<?php

namespace Database\Seeders;

use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A week of sample sales so the dashboard is not empty.
 */
class DemoActivitySeeder extends Seeder
{
    public function run(OrderService $orders): void
    {
        $cashier = User::where('email', 'cashier@store.com')->first();
        $customers = Customer::all();
        // Keep the deliberately-low products low so the alerts have content.
        $products = Product::withStock()->get()->where('stock', '>=', 20)->values();

        mt_srand(42);

        foreach (range(6, 0) as $daysAgo) {
            foreach (range(1, mt_rand(1, 4)) as $n) {
                Carbon::setTestNow(now()->subDays($daysAgo)->setTime(mt_rand(9, 20), mt_rand(0, 59)));

                $customer = $customers->random();
                $lines = $products->random(mt_rand(1, 3))
                    ->map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => mt_rand(1, 3)])
                    ->values()->all();

                try {
                    $orders->placeOrder($customer->email, null, $lines, cashier: $cashier);
                } catch (InsufficientStockException) {
                    // skip: sample data only
                }

                Carbon::setTestNow();
            }
        }

        // Don't send confirmations for invented historical orders.
        DB::table('jobs')->delete();
    }
}
