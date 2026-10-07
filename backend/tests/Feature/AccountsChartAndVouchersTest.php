<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsChartAndVouchersTest extends TestCase
{
    use AccountsTestHelpers;
    use RefreshDatabase;

    public function testChartOfAccountsIsGroupedWithBalances(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['stock' => 10, 'price' => 100, 'tax_percent' => 18]);
        $this->placeBill([[$product, 1]]);

        $tree = $this->getJson('/api/accounts')->assertOk()->json('data');

        $assets = collect($tree)->firstWhere('type', 'asset');
        $cashGroup = collect($assets['groups'])->firstWhere('group', 'cash');
        $this->assertSame('118.00', $cashGroup['accounts'][0]['balance']);
        $this->assertSame('Dr', $cashGroup['accounts'][0]['balance_side']);

        $sales = collect(collect($tree)->firstWhere('type', 'income')['groups'])->firstWhere('group', 'sales');
        $this->assertSame('Cr', collect($sales['accounts'])->firstWhere('code', '4000')['balance_side']);
    }

    public function testCreateEditAndDeleteAnAccount(): void
    {
        $this->signIn();

        $created = $this->postJson('/api/accounts', [
            'name' => 'Office Furniture', 'group' => 'fixed_asset', 'opening_balance' => 25000, 'opening_side' => 'dr',
        ])->assertCreated()->json('data');

        $this->assertSame('asset', $created['type']);
        $this->assertSame('1303', $created['code']); // after the seeded 1302
        $this->assertSame('25000.00', $created['opening_balance']);
        $this->assertTrue($created['can_delete']);

        $this->postJson('/api/accounts', ['name' => 'Bank Loan', 'group' => 'loan', 'code' => 'LOAN-1', 'opening_balance' => 1000, 'opening_side' => 'cr'])
            ->assertCreated()->assertJsonPath('data.type', 'liability')->assertJsonPath('data.opening_side', 'cr');
        $this->assertSame('-1000.00', Account::firstWhere('code', 'LOAN-1')->opening_balance);

        $this->postJson('/api/accounts', ['name' => 'Dup', 'group' => 'loan', 'code' => 'LOAN-1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/accounts', ['name' => 'Bad', 'group' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('group');

        $this->putJson("/api/accounts/{$created['id']}", ['name' => 'Furniture & Fixtures', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Furniture & Fixtures')->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/accounts/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('accounts', ['id' => $created['id']]);
    }

    public function testSystemPartyAndUsedAccountsAreProtected(): void
    {
        $this->signIn();
        $cash = Account::byCode(Account::CASH);

        // System: only the opening balance changes; never deactivated or deleted.
        $this->putJson("/api/accounts/{$cash->id}", ['name' => 'Renamed', 'opening_balance' => 500, 'opening_side' => 'dr'])
            ->assertOk()->assertJsonPath('data.name', 'Cash in Hand')->assertJsonPath('data.opening_balance', '500.00');
        $this->putJson("/api/accounts/{$cash->id}", ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->deleteJson("/api/accounts/{$cash->id}")->assertUnprocessable();

        // Party ledger: opening balance only, never deleted here.
        $party = Account::forParty(Customer::factory()->create(['name' => 'Ravi']));
        $this->putJson("/api/accounts/{$party->id}", ['name' => 'Other', 'opening_balance' => 200, 'opening_side' => 'dr'])
            ->assertOk()->assertJsonPath('data.name', 'Ravi')->assertJsonPath('data.opening_balance', '200.00');
        $this->deleteJson("/api/accounts/{$party->id}")->assertUnprocessable();

        // A ledger with entries cannot be deleted.
        $account = Account::create(['code' => '6100', 'name' => 'Advertising', 'type' => 'expense', 'group' => 'indirect_expense']);
        $this->postVoucher(Voucher::JOURNAL, [[$account, 100, 0], [Account::CASH, 0, 100]]);
        $this->deleteJson("/api/accounts/{$account->id}")->assertUnprocessable();
    }

    public function testManualJournalMustBalance(): void
    {
        $this->signIn();
        $capital = Account::byCode(Account::CAPITAL);
        $cash = Account::byCode(Account::CASH);

        $this->postJson('/api/vouchers', [
            'type' => 'journal', 'date' => now()->toDateString(), 'narration' => 'Owner capital',
            'lines' => [['account_id' => $cash->id, 'debit' => 5000], ['account_id' => $capital->id, 'credit' => 4999]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->postJson('/api/vouchers', [
            'type' => 'journal', 'date' => now()->toDateString(),
            'lines' => [['account_id' => $cash->id, 'debit' => 5000, 'credit' => 5000], ['account_id' => $capital->id, 'credit' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.debit']);

        $voucher = $this->postJson('/api/vouchers', [
            'type' => 'journal', 'date' => now()->toDateString(), 'narration' => 'Owner capital',
            'lines' => [['account_id' => $cash->id, 'debit' => 5000], ['account_id' => $capital->id, 'credit' => 5000]],
        ])->assertCreated()->json('data');

        $this->assertStringContainsString('/JV/', $voucher['number']);
        $this->assertCount(2, $voucher['entries']);
        $this->assertTrue($voucher['can_delete']);
    }

    public function testContraOnlyMovesBetweenCashAndBank(): void
    {
        $this->signIn();
        $cash = Account::byCode(Account::CASH);
        $bank = Account::byCode(Account::BANK);
        $rent = Account::byCode('6000');

        $this->postJson('/api/vouchers', [
            'type' => 'contra', 'date' => now()->toDateString(),
            'lines' => [['account_id' => $rent->id, 'debit' => 100], ['account_id' => $cash->id, 'credit' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->postJson('/api/vouchers', [
            'type' => 'contra', 'date' => now()->toDateString(), 'narration' => 'Cash deposited',
            'lines' => [['account_id' => $bank->id, 'debit' => 100], ['account_id' => $cash->id, 'credit' => 100]],
        ])->assertCreated()->assertJsonPath('data.type', 'contra');
    }

    public function testVouchersCannotBeCreatedInAllStoresMode(): void
    {
        $this->signIn();

        $this->postJson('/api/vouchers', [
            'type' => 'journal', 'date' => now()->toDateString(),
            'lines' => [['account_id' => Account::byCode(Account::CASH)->id, 'debit' => 1], ['account_id' => Account::byCode(Account::CAPITAL)->id, 'credit' => 1]],
        ], ['X-Store-Id' => 'all'])->assertUnprocessable();
    }

    public function testOnlyManualVouchersCanBeDeleted(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['stock' => 10, 'price' => 100, 'tax_percent' => 0]);
        $order = $this->placeBill([[$product, 1]]);
        $sale = Voucher::where('number', $order['invoice_number'])->firstOrFail();

        $this->deleteJson("/api/vouchers/{$sale->id}")->assertUnprocessable();

        $journal = $this->postVoucher(Voucher::JOURNAL, [[Account::CASH, 10, 0], [Account::CAPITAL, 0, 10]]);
        $this->deleteJson("/api/vouchers/{$journal->id}")->assertOk();
        $this->assertDatabaseMissing('vouchers', ['id' => $journal->id]);
        $this->assertDatabaseMissing('voucher_entries', ['voucher_id' => $journal->id]);
    }

    public function testDayBookListsVouchersWithEntriesAndSourceLabel(): void
    {
        $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $product = Product::factory()->create(['stock' => 10, 'price' => 100, 'tax_percent' => 18]);
        $order = $this->placeBill([[$product, 2]]);
        $this->postVoucher(Voucher::JOURNAL, [[Account::CASH, 50, 0], [Account::CAPITAL, 0, 50]], narration: 'Opening cash');
        $this->postVoucher(Voucher::JOURNAL, [[Account::CASH, 70, 0], [Account::CAPITAL, 0, 70]], store: $branch);

        $page = $this->getJson('/api/vouchers')->assertOk();
        $this->assertSame(2, $page->json('meta.total'));
        $this->assertSame('286.00', $page->json('totals.amount'));

        $this->getJson('/api/vouchers', ['X-Store-Id' => 'all'])->assertJsonPath('meta.total', 3);
        $this->getJson('/api/vouchers?type=sales')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/vouchers?search=opening')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/vouchers?search=capital')->assertJsonPath('meta.total', 1);

        $sale = Voucher::where('number', $order['invoice_number'])->firstOrFail();
        $this->getJson("/api/vouchers/{$sale->id}")->assertOk()
            ->assertJsonPath('data.source_type', 'order')
            ->assertJsonPath('data.source_label', "Bill {$order['invoice_number']}")
            ->assertJsonPath('data.entries.0.code', '1000')
            ->assertJsonPath('data.entries.0.debit', '236.00');
    }

    public function testPermissions(): void
    {
        $this->signIn(['reports.view']);
        $this->getJson('/api/accounts')->assertForbidden();
        $this->getJson('/api/vouchers')->assertForbidden();
        $this->getJson('/api/accounts/trial-balance')->assertForbidden();
        $this->getJson('/api/accounts/gst/gstr3b')->assertForbidden();

        $this->signIn(['accounts.view']);
        $this->getJson('/api/accounts')->assertOk();
        $this->getJson('/api/accounts/options')->assertOk();
        $this->postJson('/api/accounts', ['name' => 'X', 'group' => 'loan'])->assertForbidden();
        $this->postJson('/api/vouchers', [])->assertForbidden();
        $this->getJson('/api/reports/sales-analysis')->assertForbidden();
        $this->postJson('/api/accounts/day-closing', ['counted_cash' => 0])->assertForbidden();
    }
}
