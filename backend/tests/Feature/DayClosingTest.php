<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashClosing;
use App\Models\Product;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DayClosingTest extends TestCase
{
    use AccountsTestHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(): void
    {
        $product = Product::factory()->create(['stock' => 100, 'price' => 100, 'tax_percent' => 0]);

        // Yesterday's cash sale is today's opening cash.
        Carbon::setTestNow('2026-10-06 18:00');
        $this->signIn(null, ['name' => 'Arun']);
        $this->placeBill([[$product, 5]]);

        Carbon::setTestNow('2026-10-07 10:00');
        $this->placeBill([[$product, 1]]);                              // Arun cash 100
        $this->placeBill([[$product, 2]], ['payment_mode' => 'upi']);    // Arun upi 200

        $this->signIn(['billing.create', 'accounts.view'], ['name' => 'Priya']);
        $this->placeBill([[$product, 3]], ['amount_paid' => 500]);      // Priya cash 300
        $this->placeBill([[$product, 1]], ['payment_mode' => 'card']);   // Priya card 100

        $this->postVoucher(Voucher::CREDIT_NOTE, [[Account::SALES_RETURNS, 50, 0], [Account::CASH, 0, 50]], '2026-10-07', narration: 'Refund');
        $this->postVoucher(Voucher::EXPENSE, [['6050', 20, 0], [Account::CASH, 0, 20]], '2026-10-07', narration: 'Carry bags');
    }

    public function testSummaryByModeAndCashier(): void
    {
        $this->day();

        $data = $this->getJson('/api/accounts/day-closing?date=2026-10-07')->assertOk()->json('data');

        $this->assertSame(4, $data['bills']['count']);
        $this->assertSame('700.00', $data['bills']['total']);
        $modes = collect($data['modes'])->keyBy('mode');
        $this->assertSame(['bills' => 2, 'total' => '400.00'], ['bills' => $modes['cash']['bills'], 'total' => $modes['cash']['total']]);
        $this->assertSame('200.00', $modes['upi']['total']);
        $this->assertSame('100.00', $modes['card']['total']);

        $cashiers = collect($data['cashiers'])->keyBy('name');
        $this->assertSame('100.00', $cashiers['Arun']['modes']['cash']);
        $this->assertSame('200.00', $cashiers['Arun']['modes']['upi']);
        $this->assertSame('300.00', $cashiers['Priya']['modes']['cash']);
        $this->assertSame(2, $cashiers['Priya']['bills']);

        $this->assertSame('500.00', $data['cash']['opening']);
        $this->assertSame('400.00', $data['cash']['sales']);
        $this->assertSame('50.00', $data['cash']['refunds']);
        $this->assertSame('20.00', $data['cash']['expenses']);
        $this->assertSame('830.00', $data['cash']['expected_closing']);
        $this->assertCount(2, $data['cash_vouchers']);
    }

    public function testCashierClosesTheDayAndRecordsTheDifference(): void
    {
        $this->day();
        // Priya (billing.create) is signed in.

        $this->postJson('/api/accounts/day-closing', ['date' => '2026-10-07', 'counted_cash' => 800, 'notes' => 'Short'])
            ->assertCreated()
            ->assertJsonPath('data.expected_cash', '830.00')
            ->assertJsonPath('data.difference', '-30.00')
            ->assertJsonPath('data.closed_by', 'Priya');

        // Re-counted the same day: updated, not duplicated.
        $this->postJson('/api/accounts/day-closing', ['date' => '2026-10-07', 'counted_cash' => 830])
            ->assertOk()->assertJsonPath('data.difference', '0.00');
        $this->assertSame(1, CashClosing::count());

        $this->getJson('/api/accounts/day-closing?date=2026-10-07')
            ->assertJsonPath('data.closing.counted_cash', '830.00')
            ->assertJsonPath('recent.0.date', '2026-10-07');

        // The next day the closing is locked.
        Carbon::setTestNow('2026-10-08 09:00');
        $this->postJson('/api/accounts/day-closing', ['date' => '2026-10-07', 'counted_cash' => 1])->assertUnprocessable();
    }

    public function testClosingNeedsAStoreAndPermission(): void
    {
        $this->signIn();
        $this->postJson('/api/accounts/day-closing', ['counted_cash' => 10], ['X-Store-Id' => 'all'])->assertUnprocessable();

        $this->signIn(['orders.view']);
        $this->postJson('/api/accounts/day-closing', ['counted_cash' => 10])->assertForbidden();
        $this->getJson('/api/accounts/day-closing')->assertForbidden();
    }
}
