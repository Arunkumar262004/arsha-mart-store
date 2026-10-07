<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Support\AmountInWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaxInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const GSTIN = '27ABCDE1234F1Z5';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Store::main()->update(['state' => 'Karnataka', 'state_code' => '29', 'gstin' => '29AAAAA0000A1Z5']);
    }

    private function bill(array $extra = [], array $items = null): array
    {
        $items ??= [['product_id' => Product::factory()->create(['price' => 100, 'tax_percent' => 18, 'stock' => 50])->id, 'quantity' => 2]];

        return $this->postJson('/api/orders', [
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer Traders',
            'items' => $items,
            ...$extra,
        ])->assertCreated()->json('data');
    }

    public function testPlaceOfSupplyInAnotherStateMakesTheBillIgstAndSavesTheGstin(): void
    {
        $this->signIn();

        $order = $this->bill([
            'customer_gstin' => strtolower(self::GSTIN),
            'billing_address' => '12 MG Road, Pune',
            'place_of_supply' => '27',
        ]);

        $this->assertTrue($order['is_interstate']);
        $this->assertSame('36.00', $order['igst_amount']);
        $this->assertSame('0.00', $order['cgst_amount']);
        $this->assertSame(self::GSTIN, $order['customer_gstin']);
        $this->assertSame('27', $order['place_of_supply']);

        $customer = Customer::where('email', 'buyer@example.com')->first();
        $this->assertSame(self::GSTIN, $customer->gstin);
        $this->assertSame('12 MG Road, Pune', $customer->address);
        $this->assertSame('27', $customer->state_code);

        // The lookup returns the B2B details for auto-fill.
        $this->getJson('/api/customers/lookup?email=buyer@example.com')
            ->assertOk()
            ->assertJsonPath('data.gstin', self::GSTIN)
            ->assertJsonPath('data.state_code', '27');
    }

    public function testPlaceOfSupplyInTheStoresStateStaysCgstSgst(): void
    {
        $this->signIn();

        $order = $this->bill(['customer_gstin' => '29ABCDE1234F1Z5', 'place_of_supply' => '29']);

        $this->assertFalse($order['is_interstate']);
        $this->assertSame('18.00', $order['cgst_amount']);
        $this->assertSame('18.00', $order['sgst_amount']);
    }

    public function testInvalidGstinIsRejected(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/orders', [
            'customer_email' => 'x@example.com', 'customer_name' => 'X',
            'customer_gstin' => '27ABCDE1234',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_gstin');

        $this->postJson('/api/orders', [
            'customer_email' => 'x@example.com', 'customer_name' => 'X',
            'place_of_supply' => '99',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('place_of_supply');
    }

    public function testABillCannotOverrideThePrice(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['price' => 100, 'tax_percent' => 0, 'stock' => 5]);

        $order = $this->bill([], [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1]]);

        $this->assertSame('100.00', $order['grand_total']);
    }

    public function testInvoiceListFiltersAndTotals(): void
    {
        $this->signIn();
        $this->bill(['customer_gstin' => self::GSTIN, 'place_of_supply' => '27']);
        $this->bill(['payment_mode' => 'upi']);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.invoices', 2)
            ->assertJsonPath('summary.total', '472.00')
            ->assertJsonPath('summary.igst', '36.00');

        $this->getJson('/api/invoices?kind=b2b')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kind', 'b2b');
        $this->getJson('/api/invoices?kind=b2c')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.payment_mode', 'upi');
        $this->getJson('/api/invoices?payment_mode=upi')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/invoices?search=27abcde')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/invoices?search=buyer%20trad')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/invoices?search=INV/26-27/00002')->assertJsonPath('meta.total', 1);

        $today = now()->toDateString();
        $this->getJson("/api/invoices?from={$today}&to={$today}")->assertJsonPath('meta.total', 2);
        $this->getJson('/api/invoices?from=2020-01-01&to=2020-01-31')->assertJsonPath('meta.total', 0)->assertJsonPath('summary.total', '0.00');
    }

    public function testInvoicesAreScopedToTheUsersStore(): void
    {
        $branch = Store::create(['name' => 'Branch', 'code' => 'BR2']);
        $this->signIn();
        $mainOrder = $this->bill();

        $clerk = $this->signIn(['orders.view'], ['store_id' => $branch->id]);
        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/invoices/{$mainOrder['id']}")->assertNotFound();
        $this->getJson("/api/invoices/{$mainOrder['id']}/pdf")->assertNotFound();

        $this->signIn();
        $this->getJson('/api/invoices', ['X-Store-Id' => $branch->id])->assertJsonPath('meta.total', 0);
        $this->getJson('/api/invoices', ['X-Store-Id' => 'all'])->assertJsonPath('meta.total', 1);
        $this->assertNotNull($clerk);
    }

    public function testInvoiceDetailHasEverythingForAGstInvoice(): void
    {
        $this->signIn();
        $rice = Product::factory()->create(['name' => 'Rice', 'hsn_code' => '1006', 'unit' => 'kg', 'price' => 50, 'tax_percent' => 5, 'stock' => 100]);
        $dal = Product::factory()->create(['name' => 'Dal', 'hsn_code' => '1006', 'price' => 100, 'tax_percent' => 5, 'stock' => 100]);
        $soap = Product::factory()->create(['name' => 'Soap', 'hsn_code' => '3401', 'price' => 40, 'tax_percent' => 18, 'stock' => 100]);

        $order = $this->bill(['customer_gstin' => '29ABCDE1234F1Z5', 'billing_address' => 'Shop 4, Bengaluru', 'place_of_supply' => '29'], [
            ['product_id' => $rice->id, 'quantity' => 2],
            ['product_id' => $dal->id, 'quantity' => 1],
            ['product_id' => $soap->id, 'quantity' => 1],
        ]);

        $response = $this->getJson("/api/invoices/{$order['id']}")->assertOk();

        $response
            ->assertJsonPath('data.title', 'TAX INVOICE')
            ->assertJsonPath('data.copy', 'Original for Recipient')
            ->assertJsonPath('data.store.gstin', '29AAAAA0000A1Z5')
            ->assertJsonPath('data.store.state_code', '29')
            ->assertJsonPath('data.customer.gstin', '29ABCDE1234F1Z5')
            ->assertJsonPath('data.customer.billing_address', 'Shop 4, Bengaluru')
            ->assertJsonPath('data.place_of_supply.state', 'Karnataka')
            ->assertJsonPath('data.lines.0.hsn_code', '1006')
            ->assertJsonPath('data.lines.0.unit', 'kg')
            ->assertJsonPath('data.lines.0.taxable_value', '100.00')
            ->assertJsonPath('data.lines.0.cgst_amount', '2.50')
            ->assertJsonCount(2, 'data.hsn_summary')
            ->assertJsonPath('data.hsn_summary.0.hsn_code', '1006')
            ->assertJsonPath('data.hsn_summary.0.taxable_value', '200.00')
            ->assertJsonPath('data.hsn_summary.0.tax', '10.00')
            ->assertJsonPath('data.hsn_summary.1.hsn_code', '3401')
            ->assertJsonPath('data.hsn_summary.1.tax', '7.20')
            ->assertJsonPath('data.totals.grand_total', '257.20')
            ->assertJsonPath('data.amount_in_words', 'Rupees Two Hundred Fifty Seven and Twenty Paise Only');
    }

    public function testAnAllExemptBillIsABillOfSupply(): void
    {
        $this->signIn();
        $order = $this->bill([], [['product_id' => Product::factory()->create(['tax_percent' => 0, 'stock' => 5])->id, 'quantity' => 1]]);

        $this->getJson("/api/invoices/{$order['id']}")->assertJsonPath('data.title', 'BILL OF SUPPLY');
    }

    public function testAmountInWordsUsesTheIndianSystem(): void
    {
        $this->assertSame('Rupees One Lakh Twenty Thousand and Fifty Paise Only', AmountInWords::rupees('120000.50'));
        $this->assertSame('Rupees Two Crore Five Lakh Ninety Nine Thousand Nine Hundred Ninety Nine Only', AmountInWords::rupees(20599999));
        $this->assertSame('Rupees Eleven Only', AmountInWords::rupees(11));
        $this->assertSame('Five Paise Only', AmountInWords::rupees('0.05'));
    }

    public function testInvoicePdfDownloads(): void
    {
        $this->signIn();
        $order = $this->bill(['customer_gstin' => self::GSTIN, 'place_of_supply' => '27']);

        $response = $this->get("/api/invoices/{$order['id']}/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function testInvoicePermissions(): void
    {
        $this->signIn();
        $order = $this->bill();

        // Cashiers may open one invoice (to print it after billing) but not the list.
        $this->signIn(['billing.create']);
        $this->getJson('/api/invoices')->assertForbidden();
        $this->getJson("/api/invoices/{$order['id']}")->assertOk();

        $this->signIn(['products.view']);
        $this->getJson('/api/invoices')->assertForbidden();
        $this->getJson("/api/invoices/{$order['id']}")->assertForbidden();
        $this->get("/api/invoices/{$order['id']}/pdf")->assertForbidden();
    }
}
