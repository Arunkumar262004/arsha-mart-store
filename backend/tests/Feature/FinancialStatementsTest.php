<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Voucher;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialStatementsTest extends TestCase
{
    use AccountsTestHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Capital 1000 in cash; 15 Sep buy 10 units at 60 (stock in + purchase
     * journal); 5 Oct sell 4 at 100 (no GST) for cash; 6 Oct pay rent 50.
     */
    private function trade(): Product
    {
        $this->signIn();
        $product = Product::factory()->create(['stock' => 0, 'price' => 100, 'cost_price' => 60, 'tax_percent' => 0]);

        $this->postVoucher(Voucher::JOURNAL, [[Account::CASH, 1000, 0], [Account::CAPITAL, 0, 1000]], '2026-09-01');

        Carbon::setTestNow('2026-09-15 10:00');
        app(StockService::class)->adjust($product, 10, StockMovement::TYPE_PURCHASE, null, 'Goods received');
        $this->postVoucher(Voucher::PURCHASE, [[Account::PURCHASES, 600, 0], [Account::CASH, 0, 600]], '2026-09-15');

        Carbon::setTestNow('2026-10-05 11:00');
        $this->placeBill([[$product, 4]]);

        $this->postVoucher(Voucher::EXPENSE, [['6000', 50, 0], [Account::CASH, 0, 50]], '2026-10-06');
        Carbon::setTestNow('2026-10-07 18:00');

        return $product;
    }

    public function testTrialBalanceTotalsAgree(): void
    {
        $this->trade();

        $tb = $this->getJson('/api/accounts/trial-balance?as_of=2026-10-07')->assertOk()->json('data');

        $rows = collect($tb['rows'])->keyBy('code');
        $this->assertSame('750.00', $rows['1000']['debit']);
        $this->assertSame('1000.00', $rows['3000']['credit']);
        $this->assertSame('400.00', $rows['4000']['credit']);
        $this->assertSame('600.00', $rows['5000']['debit']);
        $this->assertSame('50.00', $rows['6000']['debit']);
        $this->assertSame('1400.00', $tb['totals']['debit']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
        $this->assertNull($tb['opening_difference']);
        $this->assertSame('360.00', $tb['closing_stock']);

        // As at 30 Sep the sale and the rent are not there yet.
        $earlier = $this->getJson('/api/accounts/trial-balance?as_of=2026-09-30')->json('data');
        $this->assertSame('1000.00', $earlier['totals']['debit']);
        $this->assertSame('600.00', $earlier['closing_stock']);
    }

    public function testProfitAndLossValuesOpeningAndClosingStock(): void
    {
        $this->trade();

        $month = $this->getJson('/api/accounts/profit-loss?from=2026-10-01&to=2026-10-07')->assertOk()->json('data');
        $this->assertSame('600.00', $month['opening_stock']);
        $this->assertSame('0.00', $month['purchases']);
        $this->assertSame('400.00', $month['sales']);
        $this->assertSame('360.00', $month['closing_stock']);
        $this->assertSame('160.00', $month['gross_profit']);
        $this->assertSame('50.00', $month['indirect_expenses']);
        $this->assertSame('110.00', $month['net_profit']);
        $this->assertSame('Cost price', $month['valuation_basis']);
        // Opening 600 + gross profit 160 = sales 400 + closing 360.
        $this->assertSame('760.00', $month['trading']['total']);
        $this->assertSame('160.00', $month['profit_loss']['total']);

        // Financial year to date: no opening stock, the purchase is inside.
        $year = $this->getJson('/api/accounts/profit-loss?from=2026-04-01&to=2026-10-07')->json('data');
        $this->assertSame('0.00', $year['opening_stock']);
        $this->assertSame('600.00', $year['purchases']);
        $this->assertSame('160.00', $year['gross_profit']);
        $this->assertSame('110.00', $year['net_profit']);
        $this->assertSame('Purchases', $year['trading']['debit'][1]['lines'][0]['name']);
    }

    public function testStockWithoutCostPriceIsValuedAtSellingPrice(): void
    {
        $this->signIn();
        Product::factory()->create(['stock' => 3, 'price' => 50, 'cost_price' => null, 'tax_percent' => 5]);

        $pl = $this->getJson('/api/accounts/profit-loss')->assertOk()->json('data');
        $this->assertSame('150.00', $pl['closing_stock']);
        $this->assertStringContainsString('selling price excluding GST for 1 product', $pl['valuation_basis']);
    }

    public function testBalanceSheetBalances(): void
    {
        $this->trade();

        $bs = $this->getJson('/api/accounts/balance-sheet?as_of=2026-10-07', ['X-Store-Id' => 'all'])->assertOk()->json('data');
        $this->assertSame('1110.00', $bs['total_assets']);
        $this->assertSame('1110.00', $bs['total_liabilities']);
        $this->assertSame('0.00', $bs['difference']);
        $this->assertSame('110.00', $bs['accumulated_profit']);

        $assets = collect($bs['assets'])->keyBy('key');
        $this->assertSame('750.00', $assets['cash']['total']);
        $this->assertSame('360.00', $assets['stock']['total']);
        $liabilities = collect($bs['liabilities'])->keyBy('key');
        $this->assertSame('1000.00', $liabilities['capital']['total']);

        // A branch with its own trade balances on its own too.
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $this->postVoucher(Voucher::JOURNAL, [[Account::BANK, 900, 0], [Account::CAPITAL, 0, 900]], '2026-10-07', $branch);
        $this->getJson('/api/accounts/balance-sheet', ['X-Store-Id' => $branch->id])->assertJsonPath('data.difference', '0.00')
            ->assertJsonPath('data.total_assets', '900.00');
    }

    public function testUnevenOpeningBalancesShowAsADifference(): void
    {
        $this->signIn();
        Account::byCode(Account::CASH)->update(['opening_balance' => 500]);

        $this->getJson('/api/accounts/balance-sheet', ['X-Store-Id' => 'all'])->assertJsonPath('data.difference', '500.00');

        $tb = $this->getJson('/api/accounts/trial-balance', ['X-Store-Id' => 'all'])->json('data');
        $this->assertSame('500.00', $tb['opening_difference']['credit']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
    }

    public function testStatementsDownloadAsPdf(): void
    {
        $this->trade();

        foreach (['trial-balance', 'profit-loss', 'balance-sheet'] as $report) {
            $response = $this->get("/api/accounts/{$report}?format=pdf")->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }
}
