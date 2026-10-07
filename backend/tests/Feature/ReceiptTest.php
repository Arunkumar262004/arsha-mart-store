<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function testAReceiptReducesWhatTheCustomerOwes(): void
    {
        $this->signIn(['payments.manage', 'billing.create']);
        $product = Product::factory()->create(['stock' => 10, 'price' => 500, 'tax_percent' => 0]);

        $this->postJson('/api/orders', [
            'customer_email' => 'owes@example.com', 'customer_name' => 'Owes Money', 'payment_mode' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();
        $customer = Customer::firstWhere('email', 'owes@example.com');
        Customer::create(['name' => 'Paid Up', 'email' => 'paid@example.com']);

        $this->getJson('/api/receipts/customers')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.outstanding', '1000.00');

        $this->postJson('/api/receipts', [
            'customer_id' => $customer->id, 'amount' => 600, 'mode' => 'bank', 'reference' => 'UTR123',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'receipt')
            ->assertJsonPath('data.total', '600.00')
            ->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'MAIN/RCT/'));

        $accounting = app(AccountingService::class);
        $this->assertSame(40000, $accounting->balance(Account::forParty($customer)));
        $this->assertSame(60000, $accounting->balance(Account::byCode(Account::BANK)));
        $this->assertSame(Voucher::RECEIPT, Voucher::latest('id')->first()->type);
        $this->getJson('/api/receipts/customers')->assertJsonPath('data.0.outstanding', '400.00');

        $list = $this->getJson('/api/receipts?search=owes')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.totals.total', '600.00');

        // Cancelling posts a reversing journal; the receipt stays listed as cancelled.
        $id = $list->json('data.0.id');
        $this->postJson("/api/receipts/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(100000, $accounting->balance(Account::forParty($customer)));
        $this->assertSame(0, $accounting->balance(Account::byCode(Account::BANK)));
        $this->getJson('/api/receipts')->assertJsonPath('meta.totals.total', '0.00')->assertJsonCount(1, 'data');
        $this->postJson("/api/receipts/{$id}/cancel")->assertUnprocessable();

        // A receipt id is not reachable through the payments URL.
        $this->getJson("/api/payments/{$id}")->assertNotFound();
    }

    public function testReceiptsAreScopedToTheStoreAndNeedPermission(): void
    {
        $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $customer = Customer::create(['name' => 'C', 'email' => 'c@example.com']);

        $this->postJson('/api/receipts', ['customer_id' => $customer->id, 'amount' => 10, 'mode' => 'cash'], ['X-Store-Id' => $branch->id])
            ->assertCreated()->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'BR2/RCT/'));

        $this->getJson('/api/receipts')->assertJsonCount(0, 'data');
        $this->getJson('/api/receipts', ['X-Store-Id' => $branch->id])->assertJsonCount(1, 'data');
        $this->getJson('/api/receipts', ['X-Store-Id' => 'all'])->assertJsonCount(1, 'data');

        $this->signIn(['expenses.manage']);
        $this->getJson('/api/receipts')->assertForbidden();
        $this->postJson('/api/receipts', ['customer_id' => $customer->id, 'amount' => 10, 'mode' => 'cash'])->assertForbidden();
    }
}
