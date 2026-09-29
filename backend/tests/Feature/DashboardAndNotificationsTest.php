<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function testDashboardSummarisesBillingAndStock(): void
    {
        Queue::fake();
        $this->signIn(['dashboard.view']);
        config(['inventory.low_stock_threshold' => 10]);

        $product = Product::factory()->create(['price' => 100, 'tax_percent' => 0, 'stock' => 50]);
        Product::factory()->create(['stock' => 3]);
        Product::factory()->create(['stock' => 0]);
        app(OrderService::class)->placeOrder('a@example.com', 'A', [['product_id' => $product->id, 'quantity' => 2]]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('billing.today_sales', '200.00')
            ->assertJsonPath('billing.today_orders', 1)
            ->assertJsonCount(7, 'billing.last_7_days')
            ->assertJsonPath('billing.last_7_days.6.total', '200.00')
            ->assertJsonPath('stock.products', 3)
            ->assertJsonPath('stock.low_stock', 1)
            ->assertJsonPath('stock.out_of_stock', 1);
    }

    public function testNotificationsShowStockAlertsOnlyToUsersWhoCanViewProducts(): void
    {
        config(['inventory.low_stock_threshold' => 10]);
        Product::factory()->create(['name' => 'Eggs', 'stock' => 0]);
        Product::factory()->create(['name' => 'Rice', 'stock' => 99]);

        // Cashier: no inventory access, so no stock alerts.
        $this->signIn(['billing.create']);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);

        $this->signIn(['products.view']);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.type', 'out_of_stock')
            ->assertJsonPath('data.0.title', 'Eggs');
    }
}
