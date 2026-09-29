<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function testCashierCanBillButNotManageInventoryOrSettings(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5]);
        $this->signIn(['billing.create', 'orders.view']);

        $this->getJson('/api/products')->assertOk(); // needed for the bill dropdown
        $this->postJson('/api/orders', [
            'customer_email' => 'a@example.com', 'customer_name' => 'A',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->getJson('/api/products/low-stock')->assertForbidden();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->postJson("/api/products/{$product->id}/stock", [])->assertForbidden();
        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
    }

    public function testEvenARoleWithEveryPermissionCannotReachSettings(): void
    {
        $this->signIn(array_keys(config('permissions')));

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();
        $this->assertNotContains('settings.manage', $this->getJson('/api/me')->assertOk()->json('permissions'));
    }

    public function testAdminHasEveryPermissionPlusSettings(): void
    {
        $this->signIn();

        $permissions = $this->getJson('/api/me')->assertOk()->json('permissions');

        $this->assertEqualsCanonicalizing([...array_keys(config('permissions')), 'settings.manage'], $permissions);
        $this->getJson('/api/users')->assertOk();
    }

    public function testUserWithoutBillingPermissionCannotCreateOrders(): void
    {
        $this->signIn(['orders.view']);

        $this->postJson('/api/orders', [])->assertForbidden();
        $this->getJson('/api/products')->assertForbidden();
    }
}
