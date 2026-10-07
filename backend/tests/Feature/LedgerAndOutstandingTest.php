<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LedgerAndOutstandingTest extends TestCase
{
    use AccountsTestHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testLedgerShowsOpeningRunningBalanceAndClosing(): void
    {
        $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $cash = Account::byCode(Account::CASH);
        $cash->update(['opening_balance' => 1000]);

        $this->postVoucher(Voucher::JOURNAL, [[Account::CASH, 300, 0], [Account::CAPITAL, 0, 300]], '2026-09-10');
        $this->postVoucher(Voucher::JOURNAL, [[Account::CASH, 500, 0], [Account::CAPITAL, 0, 500]], '2026-10-01');
        $this->postVoucher(Voucher::EXPENSE, [['6000', 200, 0], [Account::CASH, 0, 200]], '2026-10-02');
        $this->postVoucher(Voucher::JOURNAL, [[Account::CASH, 70, 0], [Account::CAPITAL, 0, 70]], '2026-10-03', $branch);

        // All stores: opening = account opening 1000 + September 300.
        $all = $this->getJson("/api/accounts/{$cash->id}/ledger?from=2026-10-01&to=2026-10-31", ['X-Store-Id' => 'all'])->assertOk();
        $all->assertJsonPath('opening.amount', '1300.00')->assertJsonPath('opening.side', 'Dr');
        $this->assertSame(['1800.00', '1600.00', '1670.00'], array_column($all->json('data'), 'balance'));
        $all->assertJsonPath('data.1.particulars', 'Rent')
            ->assertJsonPath('totals.debit', '570.00')->assertJsonPath('totals.credit', '200.00')
            ->assertJsonPath('closing.amount', '1670.00');

        // Main store only: no account opening balance, no branch entry.
        $main = $this->getJson("/api/accounts/{$cash->id}/ledger?from=2026-10-01&to=2026-10-31")->assertOk();
        $main->assertJsonPath('opening.amount', '300.00')->assertJsonPath('closing.amount', '600.00');
        $this->assertCount(2, $main->json('data'));

        // A credit balance reads Cr.
        $capital = Account::byCode(Account::CAPITAL);
        $this->getJson("/api/accounts/{$capital->id}/ledger?from=2026-09-01&to=2026-10-31")
            ->assertJsonPath('closing.amount', '800.00')->assertJsonPath('closing.side', 'Cr');
    }

    public function testOutstandingReceivablesAreAgedFirstInFirstOut(): void
    {
        Carbon::setTestNow('2026-10-07 12:00');
        $this->signIn();
        $customer = Customer::factory()->create(['name' => 'Ravi', 'phone' => '+919876543210']);
        $ledger = Account::forParty($customer);
        $other = Account::forParty(Customer::factory()->create(['name' => 'Settled']));

        // Bills: 100 days ago 1000, 45 days ago 500, 10 days ago 300.
        $this->postVoucher(Voucher::SALES, [[$ledger, 1000, 0], [Account::SALES, 0, 1000]], '2026-06-29');
        $this->postVoucher(Voucher::SALES, [[$ledger, 500, 0], [Account::SALES, 0, 500]], '2026-08-23');
        $this->postVoucher(Voucher::SALES, [[$ledger, 300, 0], [Account::SALES, 0, 300]], '2026-09-27');
        // Paid 1200: settles the 1000 bill and 200 of the 500 bill.
        $this->postVoucher(Voucher::RECEIPT, [[Account::CASH, 1200, 0], [$ledger, 0, 1200]], '2026-10-01');

        $this->postVoucher(Voucher::SALES, [[$other, 50, 0], [Account::SALES, 0, 50]], '2026-10-01');
        $this->postVoucher(Voucher::RECEIPT, [[Account::CASH, 50, 0], [$other, 0, 50]], '2026-10-02');

        $response = $this->getJson('/api/accounts/outstanding?type=receivable')->assertOk();
        $this->assertSame(1, $response->json('count'));
        $row = $response->json('data.0');
        $this->assertSame('Ravi', $row['name']);
        $this->assertSame('+919876543210', $row['phone']);
        $this->assertSame('600.00', $row['balance']);
        $this->assertSame('2026-10-01', $row['last_transaction']);
        $this->assertSame(['0_30' => '300.00', '31_60' => '300.00', '61_90' => '0.00', '90_plus' => '0.00'], $row['buckets']);
        $response->assertJsonPath('totals.balance', '600.00')->assertJsonPath('totals.31_60', '300.00');

        // Before the payment: the 29 Jun bill is 93 days old on 30 Sep.
        $before = $this->getJson('/api/accounts/outstanding?type=receivable&as_of=2026-09-30')->json('data.0');
        $this->assertSame('1800.00', $before['balance']);
        $this->assertSame(['0_30' => '300.00', '31_60' => '500.00', '61_90' => '0.00', '90_plus' => '1000.00'], $before['buckets']);

        $this->getJson('/api/accounts/outstanding?type=payable')->assertOk()->assertJsonPath('count', 0);
    }
}
