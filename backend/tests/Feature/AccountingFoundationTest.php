<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Voucher;
use App\Services\AccountingService;
use App\Services\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class AccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function bill(array $payload = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $product = Product::factory()->create(['stock' => 50, 'price' => 100, 'tax_percent' => 18]);

        return $this->postJson('/api/orders', [
            'customer_email' => 'buyer@example.com', 'customer_name' => 'Buyer',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ...$payload,
        ], $headers);
    }

    public function testDocumentNumbersRunPerStoreTypeAndFinancialYear(): void
    {
        $numbers = app(DocumentNumberService::class);
        $main = Store::main();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);

        Carbon::setTestNow('2026-10-07 10:00');
        $this->assertSame('MAIN/INV/26-27/00001', $numbers->next('invoice', $main));
        $this->assertSame('MAIN/INV/26-27/00002', $numbers->next('invoice', $main));
        $this->assertSame('BR2/INV/26-27/00001', $numbers->next('invoice', $branch));
        $this->assertSame('MAIN/QT/26-27/00001', $numbers->next('quotation', $main));

        // A new financial year starts on 1 April.
        $this->assertSame('MAIN/INV/27-28/00001', $numbers->next('invoice', $main, Carbon::parse('2027-04-01')));
        $this->assertSame('MAIN/INV/25-26/00001', $numbers->next('invoice', $main, Carbon::parse('2026-03-31')));
        Carbon::setTestNow();
    }

    public function testEachBillGetsASequentialInvoiceNumberAndASalesVoucher(): void
    {
        $this->signIn();

        $first = $this->bill(['amount_paid' => 500])->assertCreated()
            ->assertJsonPath('data.payment_mode', 'cash')
            ->json('data');
        $second = $this->bill()->assertCreated()->json('data');

        $fy = DocumentNumberService::financialYear(now());
        $this->assertSame("MAIN/INV/{$fy}/00001", $first['invoice_number']);
        $this->assertSame("MAIN/INV/{$fy}/00002", $second['invoice_number']);

        // 2 x 100 + 18% GST = 236: Dr Cash 236 / Cr Sales 200, CGST 18, SGST 18.
        $voucher = Order::find($first['id'])->voucher;
        $this->assertSame(Voucher::SALES, $voucher->type);
        $this->assertSame($first['invoice_number'], $voucher->number);
        $this->assertSame('236.00', $voucher->amount);

        $accounting = app(AccountingService::class);
        $this->assertSame(2 * 23600, $accounting->balance(Account::byCode(Account::CASH)));
        $this->assertSame(-2 * 20000, $accounting->balance(Account::byCode(Account::SALES)));
        $this->assertSame(-2 * 1800, $accounting->balance(Account::byCode(Account::OUTPUT_CGST)));
        $this->assertSame(-2 * 1800, $accounting->balance(Account::byCode(Account::OUTPUT_SGST)));
    }

    public function testCardAndUpiSalesGoToTheBank(): void
    {
        $this->signIn();
        $this->bill(['payment_mode' => 'upi'])->assertCreated();

        $this->assertSame(23600, app(AccountingService::class)->balance(Account::byCode(Account::BANK)));
        $this->assertSame(0, app(AccountingService::class)->balance(Account::byCode(Account::CASH)));
    }

    public function testACreditSaleIsOwedOnTheCustomerLedger(): void
    {
        $this->signIn();

        $this->bill(['payment_mode' => 'credit', 'amount_paid' => 300])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_paid');

        $this->bill(['payment_mode' => 'credit'])->assertCreated()->assertJsonPath('data.payment_mode', 'credit');

        $ledger = Account::forParty(Customer::firstWhere('email', 'buyer@example.com'));
        $this->assertSame('receivable', $ledger->group);
        $this->assertSame(23600, app(AccountingService::class)->balance($ledger));
    }

    public function testInterstateSalesPostIgst(): void
    {
        $this->signIn();
        $this->bill(['interstate' => true])->assertCreated();

        $this->assertSame(-3600, app(AccountingService::class)->balance(Account::byCode(Account::OUTPUT_IGST)));
    }

    public function testVoucherBalancesAreFilteredByStore(): void
    {
        $this->signIn();
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $product = Product::factory()->create(['price' => 100, 'tax_percent' => 0]);
        $this->postJson("/api/products/{$product->id}/stock", ['type' => 'restock', 'quantity' => 5], ['X-Store-Id' => $branch->id]);

        $this->postJson('/api/orders', [
            'customer_email' => 'buyer@example.com', 'customer_name' => 'Buyer',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['X-Store-Id' => $branch->id])->assertCreated()->assertJsonPath('data.invoice_number', fn ($n) => str_starts_with($n, 'BR2/INV/'));

        $cash = Account::byCode(Account::CASH);
        $accounting = app(AccountingService::class);
        $this->assertSame(10000, $accounting->balance($cash, $branch->id));
        $this->assertSame(0, $accounting->balance($cash, Store::main()->id));
    }

    public function testUnbalancedVouchersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AccountingService::class)->post(Voucher::JOURNAL, [
            [Account::CASH, 100, 0],
            [Account::SALES, 0, 99],
        ], Store::main());
    }

    public function testAManualJournalIsNumberedFromItsOwnSeries(): void
    {
        $voucher = app(AccountingService::class)->post(Voucher::JOURNAL, [
            [Account::CAPITAL, 0, 5000],
            [Account::CASH, 5000, 0],
        ], Store::main(), narration: 'Owner brought in cash');

        $this->assertStringContainsString('/JV/', $voucher->number);
        $this->assertCount(2, $voucher->entries);
    }
}
