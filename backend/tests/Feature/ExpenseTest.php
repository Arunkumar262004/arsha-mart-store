<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function balance(string $code): int
    {
        return app(AccountingService::class)->balance(Account::byCode($code));
    }

    public function testAnExpenseWithGstClaimsInputCredit(): void
    {
        $this->signIn(['expenses.manage']);
        $repairs = Account::byCode('6060');

        $accounts = $this->getJson('/api/expenses/accounts')->assertOk()->json('data');
        $this->assertContains('6060', array_column($accounts, 'code'));
        $this->assertContains('5100', array_column($accounts, 'code'));
        $this->assertNotContains('1000', array_column($accounts, 'code'));

        // GST needs the supplier's GSTIN.
        $this->postJson('/api/expenses', ['account_id' => $repairs->id, 'amount' => 1000, 'tax_percent' => 18, 'mode' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_gstin');

        $this->postJson('/api/expenses', [
            'account_id' => $repairs->id, 'amount' => 1000, 'tax_percent' => 18, 'supplier_gstin' => '33abcde1234f1z5',
            'mode' => 'bank', 'paid_to' => 'Cool Air Services', 'reference' => 'INV-77',
        ])->assertCreated()
            ->assertJsonPath('data.cgst_amount', '90.00')
            ->assertJsonPath('data.sgst_amount', '90.00')
            ->assertJsonPath('data.total', '1180.00')
            ->assertJsonPath('data.supplier_gstin', '33ABCDE1234F1Z5')
            ->assertJsonPath('data.account.code', '6060');

        $this->assertSame(100000, $this->balance('6060'));
        $this->assertSame(9000, $this->balance(Account::INPUT_CGST));
        $this->assertSame(9000, $this->balance(Account::INPUT_SGST));
        $this->assertSame(-118000, $this->balance(Account::BANK));
        $this->assertSame(Voucher::EXPENSE, Voucher::latest('id')->first()->type);
    }

    public function testAPlainExpenseAndItsCancellation(): void
    {
        $this->signIn(['expenses.manage']);
        $rent = Account::byCode('6000');

        $id = $this->postJson('/api/expenses', ['account_id' => $rent->id, 'amount' => 15000, 'mode' => 'cash', 'paid_to' => 'Landlord'])
            ->assertCreated()->assertJsonPath('data.total', '15000.00')->json('data.id');
        $this->assertSame(-1500000, $this->balance(Account::CASH));

        $this->getJson('/api/expenses?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString())
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.total', '15000.00');
        $this->getJson('/api/expenses?search=landlord')->assertJsonCount(1, 'data');

        $this->postJson("/api/expenses/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, $this->balance(Account::CASH));
        $this->assertSame(0, $this->balance('6000'));
    }

    public function testOnlyActiveExpenseAccountsAndPermission(): void
    {
        $this->signIn(['expenses.manage']);

        $this->postJson('/api/expenses', ['account_id' => Account::byCode(Account::CASH)->id, 'amount' => 10, 'mode' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $misc = Account::byCode('6090');
        $misc->update(['is_active' => false]);
        $this->postJson('/api/expenses', ['account_id' => $misc->id, 'amount' => 10, 'mode' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $this->signIn(['payments.manage']);
        $this->getJson('/api/expenses')->assertForbidden();
        $this->getJson('/api/expenses/accounts')->assertForbidden();
    }
}
