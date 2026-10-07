<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo data (php artisan db:seed) must keep working as the schema grows.
 */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function testTheDemoSeedBuildsTwoStoresWithStockSalesAndBooks(): void
    {
        $this->seed();

        $branch = Store::firstWhere('code', 'ANN');
        $this->assertNotNull($branch);
        $this->assertSame($branch->id, User::firstWhere('email', 'branch@store.com')->store_id);

        $milk = Product::firstWhere('code', 'MLK-1L');
        $this->assertSame(9, $milk->stockAt(Store::main()));
        $this->assertSame(15, $milk->stockAt($branch));

        // Every demo bill has an invoice number and a balanced sales voucher.
        $this->assertGreaterThan(0, Order::count());
        $this->assertSame(0, Order::whereNull('invoice_number')->count());
        $this->assertSame(Order::count(), Order::has('voucher')->count());
        $this->assertSame(
            (int) round(Order::sum('grand_total') * 100),
            app(AccountingService::class)->balance(Account::byCode(Account::CASH)),
        );
    }
}
