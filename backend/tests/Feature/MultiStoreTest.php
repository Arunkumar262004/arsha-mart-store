<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiStoreTest extends TestCase
{
    use RefreshDatabase;

    private function branch(array $attributes = []): Store
    {
        return Store::create(['name' => 'Branch Two', 'code' => 'BR2', ...$attributes]);
    }

    public function testMigrationCreatesTheMainStore(): void
    {
        $this->assertSame('MAIN', Store::main()->code);
    }

    public function testStockIsKeptSeparatelyPerStore(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $product = Product::factory()->create(['stock' => 10]); // main store

        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 4], ['X-Store-Id' => $branch->id])
            ->assertOk()->assertJsonPath('data.stock', 4);

        $this->getJson('/api/products')->assertJsonPath('data.0.stock', 10);
        $this->getJson('/api/products', ['X-Store-Id' => $branch->id])->assertJsonPath('data.0.stock', 4);
        $this->getJson('/api/products', ['X-Store-Id' => 'all'])->assertJsonPath('data.0.stock', 14);

        $this->getJson("/api/products/{$product->id}/stores")
            ->assertOk()
            ->assertJsonPath('meta.total', 14)
            ->assertJsonPath('data.1.store_code', 'BR2')
            ->assertJsonPath('data.1.stock', 4);
    }

    public function testABillSellsFromTheSelectedStoreOnly(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $product = Product::factory()->create(['stock' => 10, 'price' => 100, 'tax_percent' => 0]);

        // The branch has none of it yet.
        $this->postJson('/api/orders', [
            'customer_email' => 'a@example.com', 'customer_name' => 'A',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['X-Store-Id' => $branch->id])->assertUnprocessable()->assertJsonPath('shortages.0.available', 0);

        $this->postJson('/api/orders', [
            'customer_email' => 'a@example.com', 'customer_name' => 'A',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('data.store.code', 'MAIN');

        $this->assertSame(7, $product->stockAt(Store::main()));
        $this->assertSame(0, $product->stockAt($branch));
    }

    public function testAUserAssignedToAStoreAlwaysWorksThere(): void
    {
        $branch = $this->branch();
        $product = Product::factory()->create(['stock' => 5]);
        $this->signIn(['products.view', 'stock.adjust', 'billing.create'], ['store_id' => $branch->id]);

        // The header asking for the main store is ignored.
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 2], ['X-Store-Id' => Store::main()->id])
            ->assertOk()->assertJsonPath('data.stock', 2);

        $this->assertSame(5, $product->stockAt(Store::main()));
        $this->assertSame(2, $product->stockAt($branch));

        $this->getJson('/api/me')
            ->assertJsonPath('all_stores', false)
            ->assertJsonCount(1, 'stores')
            ->assertJsonPath('stores.0.code', 'BR2')
            ->assertJsonPath('user.store.code', 'BR2');
    }

    public function testAnInactiveOrUnknownStoreCannotBeSelected(): void
    {
        $this->signIn();
        $closed = $this->branch(['is_active' => false]);

        $this->getJson('/api/products', ['X-Store-Id' => $closed->id])->assertUnprocessable();
        $this->getJson('/api/products', ['X-Store-Id' => '999'])->assertUnprocessable();
    }

    public function testNothingIsCreatedWhileViewingAllStores(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['stock' => 10]);

        $this->postJson('/api/orders', [
            'customer_email' => 'a@example.com', 'customer_name' => 'A',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['X-Store-Id' => 'all'])->assertUnprocessable();
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 1], ['X-Store-Id' => 'all'])
            ->assertUnprocessable();

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function testAdminManagesStores(): void
    {
        $this->signIn();

        $id = $this->postJson('/api/stores', [
            'name' => 'Anna Nagar', 'code' => 'ann', 'gstin' => '33abcde1234f1z5', 'state' => 'Tamil Nadu', 'state_code' => '33',
        ])->assertCreated()->assertJsonPath('data.code', 'ANN')->assertJsonPath('data.gstin', '33ABCDE1234F1Z5')->json('data.id');

        $this->postJson('/api/stores', ['name' => 'X', 'code' => 'ANN', 'gstin' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'gstin']);

        $this->putJson("/api/stores/{$id}", ['name' => 'Anna Nagar', 'code' => 'ANN', 'is_active' => false])->assertOk();
        $this->putJson('/api/stores/'.Store::main()->id, ['name' => 'Main', 'code' => 'MAIN', 'is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->getJson('/api/stores/all')->assertOk()->assertJsonCount(2, 'data');
        // Only active stores can be switched to.
        $this->getJson('/api/stores')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/stores/{$id}")->assertOk();
        $this->deleteJson('/api/stores/'.Store::main()->id)->assertUnprocessable();
    }

    public function testAStoreWithHistoryCannotBeDeleted(): void
    {
        $this->signIn();
        $branch = $this->branch();
        Product::factory()->create(['stock' => 0]);
        $product = Product::first();
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 1], ['X-Store-Id' => $branch->id])->assertOk();

        $this->deleteJson("/api/stores/{$branch->id}")->assertUnprocessable();
    }

    public function testNonAdminsCannotManageStores(): void
    {
        $this->signIn(['products.view']);

        $this->postJson('/api/stores', ['name' => 'X', 'code' => 'X'])->assertForbidden();
        $this->getJson('/api/stores')->assertOk();
    }

    public function testReportsAndDashboardFollowTheSelectedStore(): void
    {
        $this->signIn();
        $branch = $this->branch();
        $product = Product::factory()->create(['stock' => 10, 'price' => 50, 'tax_percent' => 0]);
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 10], ['X-Store-Id' => $branch->id]);

        foreach ([null, $branch->id, $branch->id] as $store) {
            $this->postJson('/api/orders', [
                'customer_email' => 'a@example.com', 'customer_name' => 'A',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ], $store ? ['X-Store-Id' => $store] : [])->assertCreated();
        }

        $this->getJson('/api/dashboard')->assertJsonPath('billing.today_orders', 1);
        $this->getJson('/api/dashboard', ['X-Store-Id' => $branch->id])->assertJsonPath('billing.today_orders', 2);
        $this->getJson('/api/dashboard', ['X-Store-Id' => 'all'])->assertJsonPath('billing.today_orders', 3);

        $this->getJson('/api/reports/orders?period=this_month', ['X-Store-Id' => $branch->id])->assertJsonPath('summary.orders', 2);
    }

    public function testAUserCannotOpenABillFromAnotherStore(): void
    {
        $branch = $this->branch();
        $product = Product::factory()->create(['stock' => 10]);
        $this->signIn();
        $orderId = $this->postJson('/api/orders', [
            'customer_email' => 'a@example.com', 'customer_name' => 'A',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json('data.id');

        $this->signIn(['orders.view'], ['store_id' => $branch->id]);
        $this->getJson("/api/orders/{$orderId}")->assertNotFound();
        $this->assertSame(1, Order::count());
    }
}
