<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesAnalysisTest extends TestCase
{
    use AccountsTestHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testSalesByCategoryWithMarginModesStoresAndTopProducts(): void
    {
        Carbon::setTestNow('2026-10-07 11:30'); // a Wednesday
        $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);

        $rice = Product::factory()->create(['name' => 'Rice', 'category' => 'Grocery', 'stock' => 50, 'price' => 100, 'cost_price' => 80, 'tax_percent' => 5]);
        $dal = Product::factory()->create(['name' => 'Dal', 'category' => 'Grocery', 'stock' => 50, 'price' => 50, 'cost_price' => 30, 'tax_percent' => 5]);
        $misc = Product::factory()->create(['name' => 'Gift', 'category' => null, 'stock' => 50, 'price' => 40, 'cost_price' => null, 'tax_percent' => 0]);

        $this->placeBill([[$rice, 2], [$dal, 2]]);                    // Grocery taxable 300, cost 220
        $this->placeBill([[$misc, 1]], ['payment_mode' => 'upi']);    // Uncategorised 40, no cost

        $this->postJson("/api/products/{$rice->id}/stock", ['type' => 'restock', 'quantity' => 5], ['X-Store-Id' => $branch->id]);
        $this->placeBill([[$rice, 1]], [], ['X-Store-Id' => $branch->id]);

        $data = $this->getJson('/api/reports/sales-analysis?period=this_month')->assertOk()->json('data');

        $grocery = collect($data['categories'])->firstWhere('category', 'Grocery');
        $this->assertSame(1, $grocery['bills']);
        $this->assertSame(4, $grocery['units']);
        $this->assertSame('300.00', $grocery['sales']);
        $this->assertSame('220.00', $grocery['cost']);
        $this->assertSame('80.00', $grocery['margin']);
        $this->assertEquals(26.7, $grocery['margin_percent']);

        $uncategorised = collect($data['categories'])->firstWhere('category', 'Uncategorised');
        $this->assertSame('40.00', $uncategorised['sales']);
        $this->assertNull($uncategorised['margin_percent']);
        $this->assertSame(1, $data['summary']['uncosted_units']);

        $modes = collect($data['payment_modes'])->keyBy('mode');
        $this->assertSame('315.00', $modes['cash']['total']);
        $this->assertSame('40.00', $modes['upi']['total']);

        $this->assertSame('Rice', $data['top_by_revenue'][0]['name']);
        // Rice 200 − 160 and Dal 100 − 60; the gift has no cost price so no margin.
        $this->assertSame(['40.00', '40.00'], array_column($data['top_by_margin'], 'margin'));
        $this->assertSame(2, collect($data['weekdays'])->firstWhere('day', 'Wednesday')['bills']);
        $this->assertSame(2, $data['hours'][11]['bills']);
        $this->assertCount(1, $data['stores']);

        // All stores: the branch shows as its own row.
        $all = $this->getJson('/api/reports/sales-analysis', ['X-Store-Id' => 'all'])->json('data');
        $stores = collect($all['stores'])->keyBy('store');
        $this->assertSame(2, $stores['Main Store']['bills']);
        $this->assertSame('105.00', $stores['Branch']['average_bill']);
    }

    public function testCustomRangeAndPermission(): void
    {
        $this->signIn(['reports.view']);
        $this->getJson('/api/reports/sales-analysis?from=2026-01-01&to=2026-01-31')->assertOk()
            ->assertJsonPath('period.from', '2026-01-01')->assertJsonPath('data.summary.bills', 0);

        $this->signIn(['accounts.view']);
        $this->getJson('/api/reports/sales-analysis')->assertForbidden();
    }
}
