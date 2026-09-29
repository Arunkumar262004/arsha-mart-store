<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function testItReturnsACustomersOrdersNewestFirst(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 100]);
        $service = app(OrderService::class);

        $first = $service->placeOrder('thomas@example.com', 'Thomas', [['product_id' => $product->id, 'quantity' => 1]]);
        $this->travel(1)->minutes();
        $second = $service->placeOrder('thomas@example.com', null, [['product_id' => $product->id, 'quantity' => 2]]);
        $service->placeOrder('someone.else@example.com', 'Else', [['product_id' => $product->id, 'quantity' => 1]]);

        $this->getJson('/api/customers/THOMAS@example.com/orders')
            ->assertOk()
            ->assertJsonPath('customer.email', 'thomas@example.com')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.order_number', $second->order_number)
            ->assertJsonPath('data.1.order_number', $first->order_number)
            ->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('meta.total', 2);
    }

    public function testCustomerWithNoOrdersGetsAnEmptyList(): void
    {
        Customer::factory()->create(['email' => 'new@example.com']);

        $this->getJson('/api/customers/new@example.com/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function testUnknownEmailReturns404(): void
    {
        $this->getJson('/api/customers/nobody@example.com/orders')->assertNotFound();
    }
}
