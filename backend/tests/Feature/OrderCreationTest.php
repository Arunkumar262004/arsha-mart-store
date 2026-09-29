<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();

        Queue::fake();
    }

    public function testItCreatesAnOrderWithTotalsAndDeductsStock(): void
    {
        $toothpaste = Product::factory()->create(['price' => 50, 'tax_percent' => 18, 'stock' => 10]);
        $biscuit = Product::factory()->create(['price' => 10, 'tax_percent' => 5, 'stock' => 20]);

        $response = $this->postJson('/api/orders', [
            'customer_email' => 'thomas@example.com',
            'customer_name' => 'Thomas',
            'items' => [
                ['product_id' => $toothpaste->id, 'quantity' => 2],
                ['product_id' => $biscuit->id, 'quantity' => 5],
            ],
        ]);

        // 2 x 50 = 100 + 18 tax; 5 x 10 = 50 + 2.50 tax
        $response->assertCreated()
            ->assertJsonPath('data.subtotal', '150.00')
            ->assertJsonPath('data.tax_total', '20.50')
            ->assertJsonPath('data.grand_total', '170.50')
            ->assertJsonPath('data.customer.email', 'thomas@example.com')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '118.00')
            ->assertJsonPath('data.items.1.line_total', '52.50');

        $this->assertSame(8, $toothpaste->fresh()->stock);
        $this->assertSame(15, $biscuit->fresh()->stock);
        $this->assertDatabaseHas('customers', ['email' => 'thomas@example.com', 'name' => 'Thomas']);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function testItDispatchesTheConfirmationJobForTheNewOrder(): void
    {
        $product = Product::factory()->create(['stock' => 5]);

        $response = $this->postJson('/api/orders', $this->payload($product, 1));

        $orderId = $response->assertCreated()->json('data.id');

        Queue::assertPushed(SendOrderConfirmation::class, fn ($job) => $job->order->id === $orderId);
        Queue::assertPushed(SendOrderConfirmation::class, 1);
    }

    public function testInsufficientStockIsRejectedAndNothingChanges(): void
    {
        $product = Product::factory()->create(['name' => 'Eggs (12)', 'stock' => 2]);

        $this->postJson('/api/orders', $this->payload($product, 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity')
            ->assertJsonPath('shortages.0.available', 2)
            ->assertJsonPath('shortages.0.requested', 3);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    }

    public function testOneShortLineRejectsTheWholeOrder(): void
    {
        $plenty = Product::factory()->create(['stock' => 50]);
        $scarce = Product::factory()->create(['stock' => 1]);

        $this->postJson('/api/orders', [
            'customer_email' => 'a@example.com',
            'customer_name' => 'A',
            'items' => [
                ['product_id' => $plenty->id, 'quantity' => 5],
                ['product_id' => $scarce->id, 'quantity' => 2],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.quantity');

        $this->assertSame(50, $plenty->fresh()->stock, 'No partial deduction should happen.');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function testOrderingExactlyTheRemainingStockSucceeds(): void
    {
        $product = Product::factory()->create(['stock' => 3]);

        $this->postJson('/api/orders', $this->payload($product, 3))->assertCreated();

        $this->assertSame(0, $product->fresh()->stock);
    }

    public function testOutOfStockProductCannotBeOrdered(): void
    {
        $product = Product::factory()->outOfStock()->create();

        $this->postJson('/api/orders', $this->payload($product, 1))
            ->assertUnprocessable()
            ->assertJsonPath('shortages.0.available', 0);
    }

    public function testExistingCustomerIsReusedByEmailCaseInsensitively(): void
    {
        $customer = Customer::factory()->create(['email' => 'priya@example.com', 'name' => 'Priya']);
        $product = Product::factory()->create(['stock' => 10]);

        $this->postJson('/api/orders', [
            'customer_email' => '  PRIYA@Example.com ',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.customer.id', $customer->id);

        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('Priya', $customer->fresh()->name);
    }

    public function testNewCustomerRequiresAName(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->postJson('/api/orders', [
            'customer_email' => 'new@example.com',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_name');

        $this->assertDatabaseCount('customers', 0);
    }

    public function testSaleWithinTheStateSplitsGstIntoCgstAndSgst(): void
    {
        // 2 x 50 = 100 at 18%: CGST 9% = 9.00 and SGST 9% = 9.00
        $product = Product::factory()->create(['price' => 50, 'tax_percent' => 18, 'stock' => 10]);

        $this->postJson('/api/orders', $this->payload($product, 2))
            ->assertCreated()
            ->assertJsonPath('data.is_interstate', false)
            ->assertJsonPath('data.cgst_amount', '9.00')
            ->assertJsonPath('data.sgst_amount', '9.00')
            ->assertJsonPath('data.igst_amount', '0.00')
            ->assertJsonPath('data.tax_total', '18.00')
            ->assertJsonPath('data.grand_total', '118.00')
            ->assertJsonPath('data.items.0.cgst_percent', '9.00')
            ->assertJsonPath('data.items.0.sgst_percent', '9.00')
            ->assertJsonPath('data.items.0.igst_percent', '0.00');
    }

    public function testSaleToAnotherStateChargesIgstOnly(): void
    {
        $product = Product::factory()->create(['price' => 50, 'tax_percent' => 18, 'stock' => 10]);

        $this->postJson('/api/orders', [...$this->payload($product, 2), 'interstate' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_interstate', true)
            ->assertJsonPath('data.cgst_amount', '0.00')
            ->assertJsonPath('data.sgst_amount', '0.00')
            ->assertJsonPath('data.igst_amount', '18.00')
            ->assertJsonPath('data.grand_total', '118.00')
            ->assertJsonPath('data.items.0.igst_percent', '18.00')
            ->assertJsonPath('data.items.0.igst_amount', '18.00');
    }

    public function testTaxIsRoundedHalfUpToTheCent(): void
    {
        // 3 x 9.99 = 29.97; 5% = 1.4985 -> 1.50
        $product = Product::factory()->create(['price' => 9.99, 'tax_percent' => 5, 'stock' => 10]);

        $this->postJson('/api/orders', $this->payload($product, 3))
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '29.97')
            ->assertJsonPath('data.tax_total', '1.50')
            ->assertJsonPath('data.grand_total', '31.47');
    }

    public function testLinePricesAreSnapshottedAtTimeOfSale(): void
    {
        $product = Product::factory()->create(['price' => 100, 'tax_percent' => 12, 'stock' => 10]);

        $orderId = $this->postJson('/api/orders', $this->payload($product, 1))->json('data.id');

        $product->update(['price' => 999, 'tax_percent' => 28]);

        $item = Order::find($orderId)->items->first();
        $this->assertSame('100.00', $item->unit_price);
        $this->assertSame('12.00', $item->tax_percent);
    }

    public function testChangeIsCalculatedWhenAmountPaidIsGiven(): void
    {
        $product = Product::factory()->create(['price' => 100, 'tax_percent' => 0, 'stock' => 10]);

        $this->postJson('/api/orders', $this->payload($product, 2) + ['amount_paid' => 250])
            ->assertCreated()
            ->assertJsonPath('data.amount_paid', '250.00')
            ->assertJsonPath('data.change_due', '50.00');
    }

    public function testUnderpaymentIsRejectedWithoutTouchingStock(): void
    {
        $product = Product::factory()->create(['price' => 100, 'tax_percent' => 0, 'stock' => 10]);

        $this->postJson('/api/orders', $this->payload($product, 2) + ['amount_paid' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_paid');

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function testRequestPayloadIsValidated(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->postJson('/api/orders', [
            'customer_email' => 'not-an-email',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 0],
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => 999999, 'quantity' => 1],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'customer_email',
            'items.0.quantity',
            'items.0.product_id', // distinct
            'items.2.product_id', // does not exist
        ]);

        $this->postJson('/api/orders', ['customer_email' => 'a@example.com', 'customer_name' => 'A', 'items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, int $quantity): array
    {
        return [
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ];
    }
}
