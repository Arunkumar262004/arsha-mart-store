<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function quote(array $items, array $extra = []): array
    {
        return $this->postJson('/api/quotations', [
            'customer_name' => 'Acme Stores',
            'customer_email' => 'acme@example.com',
            'valid_until' => now()->addDays(10)->toDateString(),
            'items' => $items,
            ...$extra,
        ])->assertCreated()->json('data');
    }

    public function testCreateComputesTotalsWithEditablePrices(): void
    {
        $this->signIn();
        $a = Product::factory()->create(['price' => 100, 'tax_percent' => 18]);
        $b = Product::factory()->create(['price' => 50, 'tax_percent' => 5]);

        $q = $this->quote([
            ['product_id' => $a->id, 'quantity' => 2, 'unit_price' => 90],   // discounted
            ['product_id' => $b->id, 'quantity' => 4],                       // list price
        ]);

        $this->assertSame('MAIN/QT/'.\App\Services\DocumentNumberService::financialYear(now()).'/00001', $q['number']);
        $this->assertSame('draft', $q['status']);
        $this->assertSame('90.00', $q['items'][0]['unit_price']);
        $this->assertSame('50.00', $q['items'][1]['unit_price']);
        $this->assertSame('380.00', $q['subtotal']);           // 180 + 200
        $this->assertSame('42.40', $q['tax_total']);           // 32.40 + 10.00
        $this->assertSame('21.20', $q['cgst_amount']);
        $this->assertSame('422.40', $q['grand_total']);

        // Interstate quotations are taxed as IGST.
        $igst = $this->quote([['product_id' => $a->id, 'quantity' => 1]], ['is_interstate' => true]);
        $this->assertSame('18.00', $igst['igst_amount']);
        $this->assertSame('0.00', $igst['cgst_amount']);
    }

    public function testUpdateStatusListAndDelete(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['price' => 10, 'tax_percent' => 0]);
        $q = $this->quote([['product_id' => $p->id, 'quantity' => 1]]);

        $this->putJson("/api/quotations/{$q['id']}", [
            'customer_name' => 'Acme Stores Pvt Ltd',
            'items' => [['product_id' => $p->id, 'quantity' => 3, 'unit_price' => 8]],
        ])->assertOk()->assertJsonPath('data.grand_total', '24.00')->assertJsonPath('data.customer_name', 'Acme Stores Pvt Ltd');

        $this->putJson("/api/quotations/{$q['id']}/status", ['status' => 'sent'])->assertOk()->assertJsonPath('data.status', 'sent');
        $this->putJson("/api/quotations/{$q['id']}/status", ['status' => 'converted'])->assertUnprocessable();

        $this->getJson('/api/quotations?status=sent')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/quotations?status=draft')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/quotations?search=pvt')->assertJsonPath('meta.total', 1);

        // Only drafts can be deleted.
        $this->deleteJson("/api/quotations/{$q['id']}")->assertUnprocessable();

        $draft = $this->quote([['product_id' => $p->id, 'quantity' => 1]]);
        $this->deleteJson("/api/quotations/{$draft['id']}")->assertNoContent();
        $this->assertNull(Quotation::find($draft['id']));

        // Cancelled quotations are frozen.
        $this->putJson("/api/quotations/{$q['id']}/status", ['status' => 'cancelled'])->assertOk();
        $this->putJson("/api/quotations/{$q['id']}", [
            'customer_name' => 'X', 'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function testExpiredIsDerivedFromValidUntil(): void
    {
        $this->signIn();
        $p = Product::factory()->create();
        $q = $this->quote([['product_id' => $p->id, 'quantity' => 1]], [
            'date' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame('expired', $q['status']);
        $this->getJson('/api/quotations?status=expired')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/quotations?status=draft')->assertJsonPath('meta.total', 0);
    }

    public function testConvertMakesABillAtTheQuotedPrices(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['price' => 100, 'tax_percent' => 18, 'stock' => 10]);
        $q = $this->quote([['product_id' => $p->id, 'quantity' => 3, 'unit_price' => 80]]);

        $response = $this->postJson("/api/quotations/{$q['id']}/convert", ['payment_mode' => 'upi'])
            ->assertCreated()
            ->assertJsonPath('quotation.status', 'converted');

        $order = Order::with('items')->find($response->json('data.id'));
        $this->assertSame('80.00', $order->items[0]->unit_price);
        $this->assertSame('283.20', $order->grand_total);       // 240 + 43.20
        $this->assertSame('upi', $order->payment_mode);
        $this->assertSame(7, $p->fresh()->stockAt(Store::main()));
        $this->assertSame(Voucher::SALES, $order->voucher->type);
        $this->assertSame('283.20', $order->voucher->amount);

        $quotation = Quotation::find($q['id']);
        $this->assertSame('converted', $quotation->status);
        $this->assertSame($order->id, $quotation->converted_order_id);
        $this->assertSame($order->customer_id, $quotation->customer_id);
        $this->assertSame($order->invoice_number, $response->json('quotation.converted_invoice_number'));

        // Never twice.
        $this->postJson("/api/quotations/{$q['id']}/convert", ['payment_mode' => 'cash'])->assertUnprocessable();
        $this->assertSame(1, Order::count());
        $this->assertSame(7, $p->fresh()->stockAt(Store::main()));
    }

    public function testConvertNeedsAnEmailAndEnoughStock(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['price' => 10, 'tax_percent' => 0, 'stock' => 2]);
        $q = $this->quote([['product_id' => $p->id, 'quantity' => 5]], ['customer_email' => null]);

        $this->postJson("/api/quotations/{$q['id']}/convert")->assertUnprocessable()->assertJsonValidationErrors('customer_email');

        $this->postJson("/api/quotations/{$q['id']}/convert", ['customer_email' => 'walkin@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('shortages.0.available', 2)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertSame(0, Order::count());
        $this->assertSame('draft', Quotation::find($q['id'])->status);
        $this->assertSame(2, $p->fresh()->stockAt(Store::main()));
    }

    public function testQuotationsAreScopedToTheStore(): void
    {
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $this->signIn();
        $p = Product::factory()->create();
        $q = $this->quote([['product_id' => $p->id, 'quantity' => 1]]);

        $this->getJson('/api/quotations', ['X-Store-Id' => $branch->id])->assertJsonPath('meta.total', 0);

        $this->signIn(['quotations.manage'], ['store_id' => $branch->id]);
        $this->getJson('/api/quotations')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/quotations/{$q['id']}")->assertNotFound();
    }

    public function testPermissions(): void
    {
        $p = Product::factory()->create(['stock' => 5]);
        $this->signIn(['billing.create']);
        $this->getJson('/api/quotations')->assertForbidden();

        $this->signIn(['quotations.manage']);
        $q = $this->quote([['product_id' => $p->id, 'quantity' => 1]]);
        // Converting is billing.
        $this->postJson("/api/quotations/{$q['id']}/convert")->assertForbidden();
    }
}
