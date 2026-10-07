<?php

namespace Tests\Feature;

use App\Models\DeliveryChallan;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChallanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function issue(array $items, array $extra = []): array
    {
        return $this->postJson('/api/challans', [
            'customer_name' => 'Hotel Sagar',
            'customer_email' => 'sagar@example.com',
            'purpose' => 'sale',
            'vehicle_number' => 'ka01ab1234',
            'items' => $items,
            ...$extra,
        ])->assertCreated()->json('data');
    }

    public function testIssuingTakesStockOutWithoutAVoucher(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['price' => 20, 'tax_percent' => 5, 'stock' => 10]);

        $challan = $this->issue([['product_id' => $p->id, 'quantity' => 4]]);

        $this->assertSame('issued', $challan['status']);
        $this->assertSame('KA01AB1234', $challan['vehicle_number']);
        $this->assertStringContainsString('/DC/', $challan['number']);
        $this->assertSame(6, $p->fresh()->stockAt(Store::main()));
        $this->assertSame(0, Voucher::count());

        $movement = StockMovement::latest('id')->first();
        $this->assertSame(StockMovement::TYPE_CHALLAN, $movement->type);
        $this->assertSame(-4, $movement->quantity);
        $this->assertSame((new DeliveryChallan)->getMorphClass(), $movement->source_type);

        $this->postJson('/api/challans', [
            'customer_name' => 'X', 'items' => [['product_id' => $p->id, 'quantity' => 7]],
        ])->assertUnprocessable()->assertJsonPath('shortages.0.available', 6);
    }

    public function testSeveralChallansBecomeOneBill(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['price' => 100, 'tax_percent' => 0, 'stock' => 20]);
        $q = Product::factory()->create(['price' => 10, 'tax_percent' => 0, 'stock' => 20]);

        $one = $this->issue([['product_id' => $p->id, 'quantity' => 3], ['product_id' => $q->id, 'quantity' => 1]]);
        $two = $this->issue([['product_id' => $p->id, 'quantity' => 2]]);
        $this->assertSame(15, $p->fresh()->stockAt(Store::main()));

        $response = $this->postJson('/api/challans/invoice', [
            'challan_ids' => [$one['id'], $two['id']],
            'payment_mode' => 'credit',
        ])->assertCreated();

        $order = Order::with('items')->find($response->json('data.id'));
        $this->assertSame('510.00', $order->grand_total);
        $this->assertCount(2, $order->items);
        $this->assertSame(5, $order->items->firstWhere('product_id', $p->id)->quantity);
        $this->assertSame(Voucher::SALES, $order->voucher->type);

        // Stock is net: taken once by the challans, never twice.
        $this->assertSame(15, $p->fresh()->stockAt(Store::main()));
        $this->assertSame(19, $q->fresh()->stockAt(Store::main()));

        foreach ([$one, $two] as $c) {
            $challan = DeliveryChallan::find($c['id']);
            $this->assertSame('invoiced', $challan->status);
            $this->assertSame($order->id, $challan->order_id);
        }

        // Already invoiced.
        $this->postJson('/api/challans/invoice', ['challan_ids' => [$one['id']]])->assertUnprocessable();
        $this->postJson("/api/challans/{$one['id']}/return")->assertUnprocessable();
    }

    public function testChallansOfDifferentCustomersCannotBeCombined(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['stock' => 20]);
        $one = $this->issue([['product_id' => $p->id, 'quantity' => 1]]);
        $two = $this->issue([['product_id' => $p->id, 'quantity' => 1]], ['customer_email' => 'other@example.com', 'customer_name' => 'Other']);

        $this->postJson('/api/challans/invoice', ['challan_ids' => [$one['id'], $two['id']]])
            ->assertUnprocessable()->assertJsonValidationErrors('challan_ids');
        $this->assertSame(0, Order::count());
    }

    public function testAChallanWithoutEmailNeedsOneToInvoice(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['price' => 10, 'tax_percent' => 0, 'stock' => 5]);
        $c = $this->issue([['product_id' => $p->id, 'quantity' => 2]], ['customer_email' => null]);

        $this->postJson('/api/challans/invoice', ['challan_ids' => [$c['id']]])->assertUnprocessable()->assertJsonValidationErrors('customer_email');

        $this->postJson('/api/challans/invoice', ['challan_ids' => [$c['id']], 'customer_email' => 'new@example.com', 'amount_paid' => 50])
            ->assertCreated()
            ->assertJsonPath('data.customer.email', 'new@example.com')
            ->assertJsonPath('data.change_due', '30.00');
        $this->assertSame(3, $p->fresh()->stockAt(Store::main()));
    }

    public function testReturnAndCancelPutStockBack(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['stock' => 10]);
        $a = $this->issue([['product_id' => $p->id, 'quantity' => 4]]);
        $b = $this->issue([['product_id' => $p->id, 'quantity' => 3]]);
        $this->assertSame(3, $p->fresh()->stockAt(Store::main()));

        $this->postJson("/api/challans/{$a['id']}/return")->assertOk()->assertJsonPath('data.status', 'returned');
        $this->postJson("/api/challans/{$b['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(10, $p->fresh()->stockAt(Store::main()));

        $this->postJson("/api/challans/{$a['id']}/cancel")->assertUnprocessable();
        $this->assertSame(10, $p->fresh()->stockAt(Store::main()));

        $this->getJson('/api/challans?status=returned')->assertJsonPath('meta.total', 1);
    }

    public function testPermissionsAndStoreScope(): void
    {
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $p = Product::factory()->create(['stock' => 10]);

        $this->signIn(['billing.create']);
        $this->getJson('/api/challans')->assertForbidden();

        $this->signIn(['challans.manage']);
        $c = $this->issue([['product_id' => $p->id, 'quantity' => 1]]);
        $this->postJson('/api/challans/invoice', ['challan_ids' => [$c['id']]])->assertForbidden();

        $this->signIn(['challans.manage', 'billing.create'], ['store_id' => $branch->id]);
        $this->getJson('/api/challans')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/challans/{$c['id']}")->assertNotFound();
        $this->postJson("/api/challans/{$c['id']}/return")->assertNotFound();
        $this->postJson('/api/challans/invoice', ['challan_ids' => [$c['id']]])->assertNotFound();
    }
}
