<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function balance(string|Account $account, ?int $storeId = null): int
    {
        return app(AccountingService::class)->balance($account instanceof Account ? $account : Account::byCode($account), $storeId);
    }

    public function testACreditPurchaseAddsStockAtTheSelectedStoreAndPostsTheVoucher(): void
    {
        $this->signIn(['purchases.manage']);
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $supplier = Supplier::create(['name' => 'Wholesale Co']);
        $rice = Product::factory()->create(['stock' => 5, 'tax_percent' => 5, 'cost_price' => 40]);
        $soap = Product::factory()->create(['stock' => 0, 'tax_percent' => 18]);

        // 10 x 45.50 @5% = 455 + 11.38 + 11.38; 3 x 20 @18% = 60 + 5.40 + 5.40; freight 12.
        // Before rounding: 515 + 33.56 + 12 = 560.56 -> 561.00, round off +0.44.
        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'WC-881',
            'supplier_invoice_date' => now()->toDateString(),
            'payment_mode' => 'credit',
            'freight' => 12,
            'items' => [
                ['product_id' => $rice->id, 'quantity' => 10, 'unit_cost' => 45.50],
                ['product_id' => $soap->id, 'quantity' => 3, 'unit_cost' => 20],
            ],
        ], ['X-Store-Id' => $branch->id])->assertCreated();

        $response->assertJsonPath('data.subtotal', '515.00')
            ->assertJsonPath('data.cgst_amount', '16.78')
            ->assertJsonPath('data.sgst_amount', '16.78')
            ->assertJsonPath('data.igst_amount', '0.00')
            ->assertJsonPath('data.freight', '12.00')
            ->assertJsonPath('data.round_off', '0.44')
            ->assertJsonPath('data.grand_total', '561.00')
            ->assertJsonPath('data.amount_paid', '0.00')
            ->assertJsonPath('data.store.code', 'BR2')
            ->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'BR2/PUR/'));

        $this->assertSame(10, $rice->stockAt($branch));
        $this->assertSame(5, $rice->stockAt(Store::main()));
        $this->assertSame(3, $soap->stockAt($branch));
        $this->assertSame('45.50', $rice->fresh()->cost_price);
        $this->assertSame(2, StockMovement::where('type', StockMovement::TYPE_PURCHASE)->where('source_type', (new Purchase)->getMorphClass())->count());

        $this->assertSame(51500, $this->balance(Account::PURCHASES));
        $this->assertSame(1200, $this->balance(Account::FREIGHT_INWARD));
        $this->assertSame(1678, $this->balance(Account::INPUT_CGST));
        $this->assertSame(1678, $this->balance(Account::INPUT_SGST));
        $this->assertSame(44, $this->balance(Account::ROUND_OFF));
        $this->assertSame(-56100, $this->balance(Account::forParty($supplier)));
        $this->assertSame(51500, $this->balance(Account::PURCHASES, $branch->id));

        $purchase = Purchase::first();
        $this->assertSame(1, $purchase->vouchers()->count());
        $this->assertSame($purchase->number, $purchase->vouchers()->first()->number);
    }

    public function testACashPurchaseAlsoPaysTheSupplier(): void
    {
        $this->signIn();
        $supplier = Supplier::create(['name' => 'Cash Vendor']);
        $product = Product::factory()->create(['stock' => 0, 'tax_percent' => 0]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 25]],
        ])->assertCreated()->assertJsonPath('data.amount_paid', '100.00');

        $this->assertSame(0, $this->balance(Account::forParty($supplier)));
        $this->assertSame(-10000, $this->balance(Account::CASH));
        $this->assertSame([Voucher::PURCHASE, Voucher::PAYMENT], Voucher::orderBy('id')->pluck('type')->all());

        // A partial bank payment leaves the rest owed.
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'bank', 'amount_paid' => 60,
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 25]],
        ])->assertCreated();
        $this->assertSame(-4000, $this->balance(Account::forParty($supplier)));
        $this->assertSame(-6000, $this->balance(Account::BANK));

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'cash', 'amount_paid' => 500,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 25]],
        ])->assertUnprocessable()->assertJsonValidationErrors('amount_paid');
    }

    public function testAnInterstatePurchasePostsIgstAndRoundsDown(): void
    {
        $this->signIn();
        Store::main()->update(['state_code' => '33']);
        $supplier = Supplier::create(['name' => 'Bengaluru Traders', 'state_code' => '29']);
        $product = Product::factory()->create(['stock' => 0, 'tax_percent' => 12]);

        // 3 x 33.33 = 99.99, IGST 12% = 12.00 -> 111.99 -> 112.00 (+0.01).
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 33.33]],
        ])->assertCreated()
            ->assertJsonPath('data.is_interstate', true)
            ->assertJsonPath('data.igst_amount', '12.00')
            ->assertJsonPath('data.cgst_amount', '0.00')
            ->assertJsonPath('data.grand_total', '112.00')
            ->assertJsonPath('data.round_off', '0.01');

        $this->assertSame(1200, $this->balance(Account::INPUT_IGST));

        // 1 x 100.30, no tax, override to intra-state: 100.30 -> 100.00 (round off -0.30 credited).
        $plain = Product::factory()->create(['stock' => 0, 'tax_percent' => 0]);
        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'credit', 'is_interstate' => false,
            'items' => [['product_id' => $plain->id, 'quantity' => 1, 'unit_cost' => 100.30]],
        ])->assertCreated()
            ->assertJsonPath('data.is_interstate', false)
            ->assertJsonPath('data.round_off', '-0.30')
            ->assertJsonPath('data.grand_total', '100.00');

        $this->assertSame(1 - 30, $this->balance(Account::ROUND_OFF));
        $this->assertSame(-21200, $this->balance(Account::forParty($supplier)));
    }

    public function testCancellingAPurchaseReversesStockAndLedgers(): void
    {
        $this->signIn();
        $supplier = Supplier::create(['name' => 'Vendor']);
        $product = Product::factory()->create(['stock' => 2, 'tax_percent' => 18]);

        $id = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100]],
        ])->assertCreated()->json('data.id');
        $this->assertSame(7, $product->stockAt(Store::main()));

        $this->postJson("/api/purchases/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(2, $product->stockAt(Store::main()));
        foreach ([Account::PURCHASES, Account::INPUT_CGST, Account::INPUT_SGST, Account::CASH] as $code) {
            $this->assertSame(0, $this->balance($code), "Account {$code} should be back to zero");
        }
        $this->assertSame(0, $this->balance(Account::forParty($supplier)));
        $this->assertTrue(Voucher::where('type', Voucher::JOURNAL)->exists());
        $this->assertSame('Cancelled '.Purchase::find($id)->number, StockMovement::latest('id')->first()->note);

        $this->postJson("/api/purchases/{$id}/cancel")->assertUnprocessable();

        // Cancelled purchases drop out of the list totals.
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('meta.totals.count', 0)
            ->assertJsonPath('data.0.status', 'cancelled');
    }

    public function testACancelIsBlockedOnceTheGoodsWereSold(): void
    {
        $this->signIn();
        $supplier = Supplier::create(['name' => 'Vendor']);
        $product = Product::factory()->create(['stock' => 0, 'price' => 50, 'tax_percent' => 0]);

        $id = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 30]],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/orders', [
            'customer_email' => 'c@example.com', 'customer_name' => 'C',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->postJson("/api/purchases/{$id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->assertSame('posted', Purchase::find($id)->status);
        $this->assertSame(-9000, $this->balance(Account::forParty($supplier)));
    }

    public function testPurchasesAreScopedToTheStoreAndNeedPermission(): void
    {
        $admin = $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $supplier = Supplier::create(['name' => 'Vendor']);
        $product = Product::factory()->create(['stock' => 0, 'tax_percent' => 0]);
        $payload = ['supplier_id' => $supplier->id, 'payment_mode' => 'credit', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 10]]];

        $mainId = $this->postJson('/api/purchases', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/purchases', $payload, ['X-Store-Id' => $branch->id])->assertCreated();

        $this->getJson('/api/purchases')->assertJsonCount(1, 'data');
        $this->getJson('/api/purchases', ['X-Store-Id' => 'all'])->assertJsonCount(2, 'data');
        $this->getJson('/api/purchases?search=br2', ['X-Store-Id' => 'all'])->assertJsonCount(1, 'data');

        // A user locked to the branch cannot open the main store's purchase.
        $this->signIn(['purchases.manage'], ['store_id' => $branch->id]);
        $this->getJson("/api/purchases/{$mainId}")->assertNotFound();
        $this->getJson('/api/purchases')->assertJsonCount(1, 'data');

        $this->signIn(['orders.view']);
        $this->getJson('/api/purchases')->assertForbidden();
        $this->postJson('/api/purchases', $payload)->assertForbidden();
        $this->getJson('/api/purchasing/products')->assertForbidden();
        unset($admin);
    }
}
