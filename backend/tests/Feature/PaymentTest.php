<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function testAPaymentReducesWhatWeOweTheSupplier(): void
    {
        $this->signIn(['payments.manage', 'purchases.manage']);
        $supplier = Supplier::create(['name' => 'Vendor']);
        $product = Product::factory()->create(['stock' => 0, 'tax_percent' => 0]);

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'payment_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 80]],
        ])->assertCreated();

        $this->postJson('/api/payments', [
            'supplier_id' => $supplier->id, 'amount' => 300, 'mode' => 'cash', 'narration' => 'Part payment',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'payment')
            ->assertJsonPath('data.supplier.name', 'Vendor')
            ->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'MAIN/PAY/'));

        $accounting = app(AccountingService::class);
        $ledger = Account::forParty($supplier);
        $this->assertSame(-50000, $accounting->balance($ledger));
        $this->assertSame(-30000, $accounting->balance(Account::byCode(Account::CASH)));

        // The supplier list shows the outstanding amount (positive = we owe).
        $this->getJson('/api/suppliers')->assertOk()->assertJsonPath('data.0.outstanding', '500.00');
        $this->getJson("/api/payments?supplier_id={$supplier->id}")->assertJsonPath('meta.totals.total', '300.00');

        $this->postJson('/api/payments', ['supplier_id' => $supplier->id, 'amount' => 0, 'mode' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/payments', ['supplier_id' => $supplier->id, 'amount' => 10, 'mode' => 'card'])
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
    }

    public function testPaymentsNeedPermission(): void
    {
        $this->signIn(['purchases.manage']);
        $this->getJson('/api/payments')->assertForbidden();
    }
}
