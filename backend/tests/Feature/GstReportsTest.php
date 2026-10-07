<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GstReportsTest extends TestCase
{
    use AccountsTestHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * October: an intra-state bill (rice 5% ×2, soap 18% ×1) and an
     * inter-state one (soap ×1, shampoo 18% ×2); one September bill.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function bills(): array
    {
        $this->signIn();
        $rice = Product::factory()->create(['name' => 'Rice 1kg', 'hsn_code' => '1006', 'unit' => 'kg', 'stock' => 50, 'price' => 100, 'tax_percent' => 5]);
        $soap = Product::factory()->create(['name' => 'Bath Soap', 'hsn_code' => '3401', 'stock' => 50, 'price' => 200, 'tax_percent' => 18]);
        $shampoo = Product::factory()->create(['name' => 'Shampoo', 'hsn_code' => '3401', 'stock' => 50, 'price' => 50, 'tax_percent' => 18]);

        Carbon::setTestNow('2026-09-20 10:00');
        $this->placeBill([[$rice, 1]]);

        Carbon::setTestNow('2026-10-03 10:00');
        $first = $this->placeBill([[$rice, 2], [$soap, 1]]);
        Carbon::setTestNow('2026-10-04 10:00');
        $second = $this->placeBill([[$soap, 1], [$shampoo, 2]], ['interstate' => true]);
        Carbon::setTestNow('2026-10-07 12:00');

        return [$first, $second];
    }

    public function testGstr1GroupsB2cByRateAndSummarisesHsn(): void
    {
        [$first, $second] = $this->bills();

        $report = $this->getJson('/api/accounts/gst/gstr1?month=2026-10')->assertOk()->json('data');

        $b2c = collect($report['b2c'])->map(fn ($r) => [$r['rate'], $r['supply_type'], $r['taxable'], $r['cgst'], $r['igst']])->all();
        $this->assertSame([
            ['5.00', 'Intra-state', '200.00', '5.00', '0.00'],
            ['18.00', 'Intra-state', '200.00', '18.00', '0.00'],
            ['18.00', 'Inter-state', '300.00', '0.00', '54.00'],
        ], $b2c);

        $hsn = collect($report['hsn'])->keyBy('hsn');
        $this->assertSame('KG', $hsn['1006']['uqc']);
        $this->assertSame(2, $hsn['1006']['quantity']);
        $this->assertSame('200.00', $hsn['1006']['taxable']);
        $this->assertSame(4, $hsn['3401']['quantity']);
        $this->assertSame('500.00', $hsn['3401']['taxable']);
        $this->assertSame('18.00', $hsn['3401']['cgst']);
        $this->assertSame('54.00', $hsn['3401']['igst']);
        $this->assertSame('700.00', $report['totals']['hsn']['taxable']);

        $docs = $report['documents'][0];
        $this->assertSame([$first['invoice_number'], $second['invoice_number'], 2], [$docs['first'], $docs['last'], $docs['count']]);
    }

    public function testCreditNotesComeFromTheirVouchers(): void
    {
        $this->bills();
        $this->postVoucher(Voucher::CREDIT_NOTE, [
            [Account::SALES_RETURNS, 100, 0], [Account::OUTPUT_CGST, 9, 0], [Account::OUTPUT_SGST, 9, 0], [Account::CASH, 0, 118],
        ], '2026-10-05', narration: 'Return of soap');

        $notes = $this->getJson('/api/accounts/gst/gstr1?month=2026-10')->json('data.credit_notes');
        $this->assertCount(1, $notes);
        $this->assertSame(['100.00', '9.00', '9.00', '118.00'], [$notes[0]['taxable'], $notes[0]['cgst'], $notes[0]['sgst'], $notes[0]['total']]);

        $this->assertCount(0, $this->getJson('/api/accounts/gst/gstr1?month=2026-09')->json('data.credit_notes'));
    }

    public function testB2bInvoicesNeedACustomerGstin(): void
    {
        if (! Schema::hasColumn('orders', 'customer_gstin')) {
            $this->markTestSkipped('orders.customer_gstin is added by the tax invoice module.');
        }

        [$first] = $this->bills();
        DB::table('orders')->where('id', $first['id'])->update(['customer_gstin' => '33ABCDE1234F1Z5']);

        $report = $this->getJson('/api/accounts/gst/gstr1?month=2026-10')->json('data');
        $this->assertCount(1, $report['b2b']);
        $this->assertSame('33ABCDE1234F1Z5', $report['b2b'][0]['gstin']);
        $this->assertSame('400.00', $report['b2b'][0]['taxable']);
        // Only the inter-state bill is left in B2C.
        $this->assertSame('300.00', $report['totals']['b2c']['taxable']);
    }

    public function testGstr3bNetsOutputTaxAgainstInputCredit(): void
    {
        $this->bills();
        $this->postVoucher(Voucher::CREDIT_NOTE, [
            [Account::SALES_RETURNS, 100, 0], [Account::OUTPUT_CGST, 9, 0], [Account::OUTPUT_SGST, 9, 0], [Account::CASH, 0, 118],
        ], '2026-10-05');
        $this->postVoucher(Voucher::PURCHASE, [
            [Account::PURCHASES, 300, 0], [Account::INPUT_CGST, 4, 0], [Account::INPUT_SGST, 4, 0], [Account::INPUT_IGST, 20, 0], [Account::CASH, 0, 328],
        ], '2026-10-06');

        $report = $this->getJson('/api/accounts/gst/gstr3b?month=2026-10')->assertOk()->json('data');

        // Taxable 200 + 200 + 300 − 100 returned; CGST 5 + 18 − 9.
        $this->assertSame('600.00', $report['outward']['taxable']);
        $this->assertSame('14.00', $report['outward']['cgst']);
        $this->assertSame('54.00', $report['outward']['igst']);
        $this->assertSame('28.00', $report['itc']['total']);
        $this->assertSame('34.00', $report['heads']['igst']['payable']);
        $this->assertSame('10.00', $report['heads']['cgst']['net']);
        $this->assertSame('54.00', $report['totals']['payable']);
    }

    public function testDownloads(): void
    {
        $this->bills();

        $xlsx = $this->get('/api/accounts/gst/gstr1?month=2026-10&format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $pdf = $this->get('/api/accounts/gst/gstr3b?month=2026-10&format=pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }
}
