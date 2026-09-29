<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function testProductIsAddedWithItsOpeningStockLogged(): void
    {
        $user = $this->signIn(['products.view', 'products.manage']);

        $id = $this->postJson('/api/products', [
            'name' => 'Amul Butter 100g', 'code' => 'amul-but-100', 'price' => 56, 'tax_percent' => 12, 'stock' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'AMUL-BUT-100')
            ->assertJsonPath('data.stock', 30)
            ->json('data.id');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $id, 'type' => 'initial', 'quantity' => 30, 'stock_after' => 30, 'user_id' => $user->id,
        ]);
    }

    public function testProductDetailsAreValidatedAndStockCannotBeEditedDirectly(): void
    {
        $this->signIn(['products.manage']);
        $existing = Product::factory()->create(['code' => 'TAKEN']);

        $this->postJson('/api/products', ['name' => '', 'code' => 'taken', 'price' => 0, 'tax_percent' => 150, 'stock' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code', 'price', 'tax_percent', 'stock']);

        $this->putJson("/api/products/{$existing->id}", [
            'name' => 'Renamed', 'code' => 'TAKEN', 'price' => 10, 'tax_percent' => 5, 'stock' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('stock');

        $this->putJson("/api/products/{$existing->id}", ['name' => 'Renamed', 'code' => 'TAKEN', 'price' => 10, 'tax_percent' => 5])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
    }

    public function testRestockAndCorrectionUpdateStockAndAreAudited(): void
    {
        $this->signIn(['products.view', 'stock.adjust']);
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 20])
            ->assertOk()->assertJsonPath('data.stock', 25);

        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'correction', 'quantity' => -3, 'note' => '3 packs damaged'])
            ->assertOk()->assertJsonPath('data.stock', 22);

        $this->getJson("/api/products/{$product->id}/movements")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'correction')
            ->assertJsonPath('data.0.stock_after', 22)
            ->assertJsonPath('data.1.quantity', 20);
    }

    public function testStockCanNeverGoNegativeAndRulesAreEnforced(): void
    {
        $this->signIn(['stock.adjust']);
        $product = Product::factory()->create(['stock' => 2]);

        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'correction', 'quantity' => -5, 'note' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'correction', 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->assertSame(2, $product->fresh()->stock);
    }

    public function testSaleIsRecordedInTheStockLogWithTheCashier(): void
    {
        Queue::fake();
        $cashier = $this->signIn(['billing.create']);
        $product = Product::factory()->create(['stock' => 10]);

        $orderId = $this->postJson('/api/orders', [
            'customer_email' => 'a@example.com', 'customer_name' => 'A',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertCreated()->json('data.id');

        $movement = StockMovement::firstWhere('product_id', $product->id);
        $this->assertSame(['sale', -4, 6, $orderId, $cashier->id], [
            $movement->type, $movement->quantity, $movement->stock_after, $movement->order_id, $movement->user_id,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'created_by' => $cashier->id]);
    }
}
