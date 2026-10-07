<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function bill(Product $product, int $qty, string $mode = 'cash', string $email = 'a@example.com'): void
    {
        $this->postJson('/api/orders', [
            'customer_email' => $email, 'customer_name' => 'Buyer',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'payment_mode' => $mode,
        ])->assertCreated();
    }

    public function testOverviewFigures(): void
    {
        $this->signIn();
        $rice = Product::factory()->create(['name' => 'Rice', 'category' => 'Grocery', 'price' => 100, 'cost_price' => 80, 'tax_percent' => 0, 'stock' => 100]);
        $soap = Product::factory()->create(['name' => 'Soap', 'category' => 'Personal Care', 'price' => 50, 'cost_price' => null, 'tax_percent' => 0, 'stock' => 5]);

        // Last month, same days: 1 bill of Rice x1.
        Carbon::setTestNow(now()->startOfMonth()->subMonthNoOverflow()->setTime(10, 0));
        $this->bill($rice, 1);
        Carbon::setTestNow();

        // This month: Rice x3 (cash), Soap x2 (UPI, another customer).
        $this->bill($rice, 3);
        $this->bill($soap, 2, 'upi', 'b@example.com');

        $o = $this->getJson('/api/dashboard')->assertOk()->json('overview');

        $this->assertSame('400.00', $o['kpis']['sales']['value']);
        $this->assertSame('100.00', $o['kpis']['sales']['previous']);
        $this->assertEquals(300.0, $o['kpis']['sales']['change']);
        $this->assertSame(2, $o['kpis']['orders']['value']);
        $this->assertSame(2, $o['kpis']['customers']['value']);
        // Profit: rice 300 - 240; soap has no cost price, so it adds nothing.
        $this->assertSame('60.00', $o['kpis']['profit']['value']);
        $this->assertCount(30, $o['kpis']['sales']['series']);
        $this->assertEquals(400, end($o['kpis']['sales']['series']));

        $this->assertSame(now()->year, $o['monthly']['year']);
        $this->assertSame('400.00', $o['monthly']['months'][now()->month - 1]['total']);

        $this->assertSame('Rice', $o['best_sellers'][0]['name']);
        $this->assertSame(3, $o['best_sellers'][0]['units']);
        $this->assertEquals(200.0, $o['best_sellers'][0]['change']);
        $this->assertNull($o['best_sellers'][1]['change']);

        $modes = collect($o['payment_modes'])->keyBy('mode');
        $this->assertSame(1, $modes['cash']['orders']);
        $this->assertSame(1, $modes['upi']['orders']);
        $this->assertSame(0, $modes['credit']['orders']);

        $this->assertSame(['Grocery', 'Personal Care'], array_column($o['categories'], 'name'));

        // Stock value: rice 96 x 80 + soap 3 x 50 (no cost price: selling price).
        $this->assertSame('7830.00', $o['inventory']['value']);
        $this->assertSame(1, $o['inventory']['low_stock']);

        $this->assertSame(2, $o['store']['today_orders']);
        $this->assertSame('paid', $o['recent'][0]['status']);
        $this->assertSame('upi', $o['recent'][0]['payment_mode']);
    }

    public function testCollectedAndOutstanding(): void
    {
        $this->signIn();
        $rice = Product::factory()->create(['name' => 'Rice', 'price' => 100, 'tax_percent' => 0, 'stock' => 100]);

        $this->bill($rice, 2);                                   // cash 200, collected
        $this->bill($rice, 3, 'credit', 'owes@example.com');     // credit 300, owed

        $o = $this->getJson('/api/dashboard')->assertOk()->json('overview');

        $this->assertSame('200.00', $o['kpis']['collected']['value']);
        $this->assertSame('300.00', $o['kpis']['outstanding']['value']);
    }

    public function testMonthlyChartForAnotherYear(): void
    {
        $this->signIn();
        $this->getJson('/api/dashboard?year=2024')->assertOk()
            ->assertJsonPath('overview.monthly.year', 2024)
            ->assertJsonPath('overview.monthly.total', '0.00');
        $this->getJson('/api/dashboard?year=abc')->assertUnprocessable();
    }
}
