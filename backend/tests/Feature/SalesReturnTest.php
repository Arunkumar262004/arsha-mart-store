<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    private function balance(string|Account $account, ?int $storeId = null): int
    {
        return app(AccountingService::class)->balance($account instanceof Account ? $account : Account::byCode($account), $storeId);
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    private function sell(array $items, array $payload = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/orders', [
            'customer_email' => 'buyer@example.com', 'customer_name' => 'Buyer', 'items' => $items, ...$payload,
        ], $headers)->assertCreated();
    }

    public function testReturnsAreLimitedAndStockGoesBackToTheBillsStore(): void
    {
        $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $product = Product::factory()->create(['stock' => 0, 'price' => 99.99, 'tax_percent' => 18]);
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 10], ['X-Store-Id' => $branch->id]);

        $order = $this->sell([['product_id' => $product->id, 'quantity' => 3]], [], ['X-Store-Id' => $branch->id])->json('data');
        $this->assertSame(7, $product->stockAt($branch));

        $lookup = $this->getJson('/api/sales-returns/lookup?number='.urlencode($order['invoice_number']), ['X-Store-Id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('data.items.0.returnable', 3)
            ->json('data');
        $itemId = $lookup['items'][0]['id'];

        // The lookup works by order number too; not from another store's screen.
        $this->getJson('/api/sales-returns/lookup?number='.$order['order_number'], ['X-Store-Id' => $branch->id])->assertOk();
        $this->getJson('/api/sales-returns/lookup?number='.urlencode($order['invoice_number']))->assertNotFound();

        $this->postJson('/api/sales-returns', [
            'order_id' => $order['id'], 'refund_mode' => 'cash',
            'items' => [['order_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.store.code', 'BR2')
            ->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'BR2/SR/'));

        $this->assertSame(8, $product->stockAt($branch));
        $this->assertSame(0, $product->stockAt(Store::main()));
        $this->assertTrue(StockMovement::where('type', StockMovement::TYPE_SALE_RETURN)->where('store_id', $branch->id)->exists());

        $this->postJson('/api/sales-returns', [
            'order_id' => $order['id'], 'refund_mode' => 'cash',
            'items' => [['order_item_id' => $itemId, 'quantity' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->getJson('/api/sales-returns/lookup?number='.urlencode($order['invoice_number']), ['X-Store-Id' => $branch->id])
            ->assertJsonPath('data.items.0.returned', 1)
            ->assertJsonPath('data.items.0.returnable', 2);
    }

    public function testReturningEverythingReversesTheSaleExactly(): void
    {
        $this->signIn();
        // Odd prices so per-unit tax rounding differs from the line's.
        $a = Product::factory()->create(['stock' => 20, 'price' => 33.33, 'tax_percent' => 5]);
        $b = Product::factory()->create(['stock' => 20, 'price' => 10.01, 'tax_percent' => 18]);

        $order = $this->sell([
            ['product_id' => $a->id, 'quantity' => 3],
            ['product_id' => $b->id, 'quantity' => 7],
        ])->json('data');
        $items = Order::find($order['id'])->items->keyBy('product_id');

        // Return in pieces: 1 + 2 of A, 3 + 4 of B.
        foreach ([[1, 3], [2, 4]] as [$qa, $qb]) {
            $this->postJson('/api/sales-returns', [
                'order_id' => $order['id'], 'refund_mode' => 'cash',
                'items' => [
                    ['order_item_id' => $items[$a->id]->id, 'quantity' => $qa],
                    ['order_item_id' => $items[$b->id]->id, 'quantity' => $qb],
                ],
            ])->assertCreated();
        }

        // Sales vs Sales Returns and output GST cancel out to the paisa; cash is back to zero.
        $this->assertSame(0, $this->balance(Account::SALES) + $this->balance(Account::SALES_RETURNS));
        $this->assertSame(0, $this->balance(Account::OUTPUT_CGST));
        $this->assertSame(0, $this->balance(Account::OUTPUT_SGST));
        $this->assertSame(0, $this->balance(Account::CASH));
        $this->assertSame(2, Voucher::where('type', Voucher::CREDIT_NOTE)->count());
        $this->assertSame(20, $a->stockAt(Store::main()));
        $this->assertSame(20, $b->stockAt(Store::main()));
    }

    public function testInterstateReturnReversesIgstAndCreditRefundReducesTheReceivable(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['stock' => 10, 'price' => 200, 'tax_percent' => 12]);

        $order = $this->sell([['product_id' => $product->id, 'quantity' => 2]], ['payment_mode' => 'credit', 'interstate' => true])->json('data');
        $ledger = Account::forParty(Customer::firstWhere('email', 'buyer@example.com'));
        $this->assertSame(44800, $this->balance($ledger));

        $this->postJson('/api/sales-returns', [
            'order_id' => $order['id'], 'refund_mode' => 'credit',
            'items' => [['order_item_id' => Order::find($order['id'])->items->first()->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.igst_amount', '24.00')
            ->assertJsonPath('data.cgst_amount', '0.00')
            ->assertJsonPath('data.grand_total', '224.00');

        $this->assertSame(22400, $this->balance($ledger));
        $this->assertSame(-2400, $this->balance(Account::OUTPUT_IGST));
        $this->assertSame(20000, $this->balance(Account::SALES_RETURNS));

        $this->getJson('/api/sales-returns')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.totals.grand_total', '224.00');
    }

    public function testAStoreLockedUserCannotReturnAnotherStoresBill(): void
    {
        $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $product = Product::factory()->create(['stock' => 10, 'price' => 10, 'tax_percent' => 0]);
        $order = $this->sell([['product_id' => $product->id, 'quantity' => 1]])->json('data');

        $this->signIn(['returns.manage'], ['store_id' => $branch->id]);
        $this->postJson('/api/sales-returns', [
            'order_id' => $order['id'], 'refund_mode' => 'cash',
            'items' => [['order_item_id' => Order::find($order['id'])->items->first()->id, 'quantity' => 1]],
        ])->assertNotFound();
        $this->getJson('/api/sales-returns/lookup?number='.urlencode($order['invoice_number']))->assertNotFound();
    }
}
