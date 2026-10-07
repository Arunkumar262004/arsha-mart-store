<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private function balance(string|Account $account): int
    {
        return app(AccountingService::class)->balance($account instanceof Account ? $account : Account::byCode($account));
    }

    public function testAReturnAgainstAPurchaseIsLimitedAndPostsADebitNote(): void
    {
        $this->signIn(['purchases.manage', 'returns.manage']);
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $supplier = Supplier::create(['name' => 'Vendor']);
        $product = Product::factory()->create(['stock' => 0, 'tax_percent' => 18]);

        $purchaseId = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 50]],
        ], ['X-Store-Id' => $branch->id])->assertCreated()->json('data.id');
        // 500 + 45 + 45 = 590 owed.

        // Returned from whichever store is selected, the stock leaves the purchase's store.
        $this->postJson('/api/purchase-returns', [
            'supplier_id' => $supplier->id, 'purchase_id' => $purchaseId, 'refund_mode' => 'credit', 'reason' => 'Damaged',
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.store.code', 'BR2')
            ->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'BR2/PR/'))
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.cgst_amount', '18.00')
            ->assertJsonPath('data.grand_total', '236.00');

        $this->assertSame(6, $product->stockAt($branch));
        $this->assertSame(-59000 + 23600, $this->balance(Account::forParty($supplier)));
        $this->assertSame(-20000, $this->balance(Account::PURCHASE_RETURNS));
        $this->assertSame(4500 - 1800, $this->balance(Account::INPUT_CGST));
        $this->assertSame(Voucher::DEBIT_NOTE, Voucher::latest('id')->first()->type);

        // Only 6 left to return.
        $this->postJson('/api/purchase-returns', [
            'supplier_id' => $supplier->id, 'purchase_id' => $purchaseId, 'refund_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 7]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->getJson("/api/purchases/{$purchaseId}", ['X-Store-Id' => $branch->id])
            ->assertJsonPath('data.items.0.returnable', 6);

        // A purchase with returns cannot be cancelled.
        $this->postJson("/api/purchases/{$purchaseId}/cancel")->assertUnprocessable();

        // Wrong supplier.
        $other = Supplier::create(['name' => 'Other']);
        $this->postJson('/api/purchase-returns', [
            'supplier_id' => $other->id, 'purchase_id' => $purchaseId, 'refund_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_id');
    }

    public function testAnUnlinkedReturnRefundedInCash(): void
    {
        $this->signIn(['returns.manage']);
        $supplier = Supplier::create(['name' => 'Vendor']);
        $product = Product::factory()->create(['stock' => 3, 'tax_percent' => 5, 'cost_price' => 20]);

        // Cost defaults to the product's cost price.
        $this->postJson('/api/purchase-returns', [
            'supplier_id' => $supplier->id, 'refund_mode' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.grand_total', '42.00');

        $this->assertSame(1, $product->stockAt(Store::main()));
        $this->assertSame(4200, $this->balance(Account::CASH));
        $this->assertNull(Supplier::find($supplier->id)->ledger, "A cash refund never touches the supplier ledger");

        // Cannot send back more than the store holds.
        $this->postJson('/api/purchase-returns', [
            'supplier_id' => $supplier->id, 'refund_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->getJson('/api/purchase-returns')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.totals.grand_total', '42.00');
    }

    public function testReturnsNeedPermission(): void
    {
        $this->signIn(['purchases.manage']);
        $this->getJson('/api/purchase-returns')->assertForbidden();
        $this->getJson('/api/sales-returns')->assertForbidden();
    }
}
