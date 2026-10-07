<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    private Store $main;

    private Store $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->main = Store::main();
        $this->branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
    }

    private function send(Product $product, int $quantity, array $headers = []): array
    {
        return $this->postJson('/api/transfers', [
            'to_store_id' => $this->branch->id,
            'vehicle_number' => 'ka05mn0001',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ], $headers)->assertCreated()->json('data');
    }

    public function testDispatchThenReceiveMovesStock(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['stock' => 10]);

        $t = $this->send($p, 4);
        $this->assertSame('in_transit', $t['status']);
        $this->assertStringStartsWith('MAIN/ST/', $t['number']);
        $this->assertSame(6, $p->stockAt($this->main));
        $this->assertSame(0, $p->stockAt($this->branch));

        $this->postJson("/api/transfers/{$t['id']}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame(6, $p->stockAt($this->main));
        $this->assertSame(4, $p->stockAt($this->branch));

        $this->assertSame(
            [StockMovement::TYPE_TRANSFER_OUT, StockMovement::TYPE_TRANSFER_IN],
            StockMovement::orderBy('id')->pluck('type')->all(),
        );
        $this->assertSame($this->branch->id, StockMovement::orderByDesc('id')->first()->store_id);
        $this->assertSame(0, Voucher::count());

        // Received once only.
        $this->postJson("/api/transfers/{$t['id']}/receive")->assertUnprocessable();
        $this->postJson("/api/transfers/{$t['id']}/cancel")->assertUnprocessable();
        $this->assertSame(4, $p->stockAt($this->branch));
    }

    public function testCancelPutsStockBackAtTheSource(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['stock' => 10]);
        $t = $this->send($p, 3);

        $this->postJson("/api/transfers/{$t['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(10, $p->stockAt($this->main));
        $this->assertSame(0, $p->stockAt($this->branch));
    }

    public function testInsufficientStockAndSameStoreAreRejected(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['stock' => 2]);

        $this->postJson('/api/transfers', [
            'to_store_id' => $this->branch->id,
            'items' => [['product_id' => $p->id, 'quantity' => 3]],
        ])->assertUnprocessable()->assertJsonPath('shortages.0.available', 2);

        $this->postJson('/api/transfers', [
            'to_store_id' => $this->main->id,
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_store_id');

        $this->branch->update(['is_active' => false]);
        $this->postJson('/api/transfers', [
            'to_store_id' => $this->branch->id,
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_store_id');

        $this->assertSame(2, $p->stockAt($this->main));
    }

    public function testOnlyTheDestinationStoreCanReceive(): void
    {
        $p = Product::factory()->create(['stock' => 10]);
        $this->signIn(['transfers.manage'], ['store_id' => $this->main->id]);
        // Staff of one store can still pick the other stores as destination.
        $this->getJson('/api/transfers/destinations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'BR2');
        $t = $this->send($p, 5);

        // The sender sees it as outgoing but cannot receive it.
        $this->getJson('/api/transfers?direction=outgoing')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.can_receive', false);
        $this->postJson("/api/transfers/{$t['id']}/receive")->assertForbidden();

        // Branch staff see it as incoming, can receive but not cancel.
        $this->signIn(['transfers.manage'], ['store_id' => $this->branch->id]);
        $this->getJson('/api/transfers?direction=incoming')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.can_receive', true);
        $this->getJson('/api/transfers?direction=outgoing')->assertJsonPath('meta.total', 0);
        $this->postJson("/api/transfers/{$t['id']}/cancel")->assertForbidden();
        $this->postJson("/api/transfers/{$t['id']}/receive")->assertOk();

        $this->assertSame(5, $p->stockAt($this->main));
        $this->assertSame(5, $p->stockAt($this->branch));

        // A third store sees nothing.
        $third = Store::create(['name' => 'Third', 'code' => 'TH3']);
        $this->signIn(['transfers.manage'], ['store_id' => $third->id]);
        $this->getJson('/api/transfers')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/transfers/{$t['id']}")->assertNotFound();
    }

    public function testAllStoresModeListsEveryTransfer(): void
    {
        $this->signIn();
        $p = Product::factory()->create(['stock' => 10]);
        $this->send($p, 1);

        $this->getJson('/api/transfers', ['X-Store-Id' => 'all'])->assertJsonPath('meta.total', 1);
        $this->getJson('/api/transfers?direction=incoming', ['X-Store-Id' => $this->branch->id])->assertJsonPath('meta.total', 1);
    }

    public function testPermission(): void
    {
        $this->signIn(['products.view']);
        $this->getJson('/api/transfers')->assertForbidden();
        $this->postJson('/api/transfers', [])->assertForbidden();
    }
}
