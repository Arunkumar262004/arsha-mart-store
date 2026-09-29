<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(Carbon::parse('2026-09-15 10:00'));

        $this->admin = $this->signIn(attributes: ['name' => 'Admin Anu']);
        $this->cashier = User::factory()->withRole($this->makeRole(['billing.create']))->create(['name' => 'Cashier Karthik']);
        $this->product = Product::factory()->create(['name' => 'Basmati Rice', 'price' => '100.00', 'tax_percent' => 0, 'stock' => 100]);
    }

    private function bill(string $email, int $quantity, User $by): void
    {
        app(OrderService::class)->placeOrder($email, 'Customer '.$email, [['product_id' => $this->product->id, 'quantity' => $quantity]], cashier: $by);
    }

    public function testOrderReportFiltersByEmployeeAndPeriod(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 10:00'));
        $this->bill('old@example.com', 1, $this->cashier);
        $this->travelTo(Carbon::parse('2026-09-15 10:00'));

        $this->bill('arun@example.com', 2, $this->cashier);
        $this->bill('bala@example.com', 3, $this->admin);

        $this->getJson('/api/reports/orders?period=this_month')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-09-01')
            ->assertJsonPath('summary.orders', 2)
            ->assertJsonPath('summary.grand_total', '500.00');

        $this->getJson("/api/reports/orders?period=this_month&employee_id={$this->cashier->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cashier', 'Cashier Karthik')
            ->assertJsonPath('data.0.customer.email', 'arun@example.com')
            ->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('summary.grand_total', '200.00');

        $this->getJson('/api/reports/orders?period=last_month')
            ->assertOk()
            ->assertJsonPath('summary.orders', 1)
            ->assertJsonPath('data.0.customer.email', 'old@example.com');
    }

    public function testCustomRangeNeedsBothDates(): void
    {
        $this->getJson('/api/reports/orders?period=custom&from=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/reports/orders?period=custom&from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->bill('arun@example.com', 1, $this->cashier);

        $this->getJson('/api/reports/orders?period=custom&from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertJsonPath('summary.orders', 1);
    }

    public function testBillKeepsTheEmployeeNameAfterTheAccountIsDeleted(): void
    {
        $this->bill('arun@example.com', 1, $this->cashier);
        $this->cashier->delete();

        $this->getJson('/api/reports/orders')
            ->assertOk()
            ->assertJsonPath('data.0.cashier', 'Cashier Karthik');
    }

    public function testCustomerReportRanksCustomersBySpend(): void
    {
        $this->bill('arun@example.com', 1, $this->cashier);
        $this->bill('bala@example.com', 3, $this->cashier);
        $this->bill('bala@example.com', 1, $this->cashier);

        $this->getJson('/api/reports/customers?period=this_month')
            ->assertOk()
            ->assertJsonPath('summary.customers', 2)
            ->assertJsonPath('summary.orders', 3)
            ->assertJsonPath('summary.total_spent', '500.00')
            ->assertJsonPath('data.0.email', 'bala@example.com')
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.0.total_spent', '400.00')
            ->assertJsonPath('data.1.email', 'arun@example.com');

        $this->getJson('/api/reports/customers?search=arun')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function testStockReportShowsWhoAdjustedStock(): void
    {
        app(StockService::class)->adjust($this->product, 20, StockMovement::TYPE_RESTOCK, $this->admin, 'Supplier delivery');
        $this->bill('arun@example.com', 5, $this->cashier);

        $this->getJson('/api/reports/stock?type=adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user', 'Admin Anu')
            ->assertJsonPath('data.0.product.name', 'Basmati Rice')
            ->assertJsonPath('data.0.quantity', 20);

        $this->getJson('/api/reports/stock')
            ->assertOk()
            ->assertJsonPath('summary.entries', 2)
            ->assertJsonPath('summary.units_sold', 5)
            ->assertJsonPath('summary.units_restocked', 20);

        $this->getJson("/api/reports/stock?employee_id={$this->cashier->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'sale');
    }

    public function testEmployeeReportTotalsEachEmployee(): void
    {
        $this->bill('arun@example.com', 2, $this->cashier);
        $this->bill('bala@example.com', 1, $this->cashier);
        app(StockService::class)->adjust($this->product, -4, StockMovement::TYPE_CORRECTION, $this->admin, 'Damaged');

        $response = $this->getJson('/api/reports/employees?period=this_month')
            ->assertOk()
            ->assertJsonPath('summary.orders', 2)
            ->assertJsonPath('summary.sales_total', '300.00')
            ->assertJsonPath('data.0.name', 'Cashier Karthik')
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.0.customers_count', 2);

        $admin = collect($response->json('data'))->firstWhere('name', 'Admin Anu');
        $this->assertSame(1, $admin['adjustments_count']);
        $this->assertSame(4, $admin['units_removed']);
    }

    public function testReportsDownloadAsExcelWithOneSheetPerDataSet(): void
    {
        $this->bill('arun@example.com', 2, $this->cashier);
        $this->bill('bala@example.com', 1, $this->admin);

        $response = $this->get("/api/reports/orders/export?format=xlsx&period=this_month&employee_id={$this->cashier->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('order-report-2026-09-01-to-2026-09-15.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'report');
        file_put_contents($path, $response->getContent());
        $book = IOFactory::load($path);
        unlink($path);

        $this->assertSame(['Summary', 'Bills with products', 'Bills', 'Bill items'], $book->getSheetNames());
        $grouped = $book->getSheetByName('Bills with products');
        $this->assertStringStartsWith('ORD-', $grouped->getCell('A2')->getValue()); // the bill row...
        $this->assertTrue($grouped->getCell('A2')->getStyle()->getFont()->getBold());
        $this->assertSame('Basmati Rice', $grouped->getCell('A3')->getValue()); // ...then its product
        $this->assertSame(1, $grouped->getRowDimension(3)->getOutlineLevel());

        $bills = $book->getSheetByName('Bills');
        $this->assertSame('Bill no.', $bills->getCell('A1')->getValue());
        $this->assertSame('Cashier Karthik', $bills->getCell('E2')->getValue());
        $this->assertSame('Total', $bills->getCell('A3')->getValue()); // only the cashier's bill
        $this->assertSame('Basmati Rice', $book->getSheetByName('Bill items')->getCell('D2')->getValue());
    }

    public function testEveryReportDownloadsAsPdfAndExcel(): void
    {
        $this->bill('arun@example.com', 1, $this->cashier);
        app(StockService::class)->adjust($this->product, 5, StockMovement::TYPE_RESTOCK, $this->admin);

        foreach (['orders', 'customers', 'stock', 'employees'] as $report) {
            $this->get("/api/reports/{$report}/export?format=pdf&period=last_6_months")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
            $this->get("/api/reports/{$report}/export?format=xlsx&period=custom&from=2026-09-01&to=2026-09-30")
                ->assertOk();
        }

        $this->getJson('/api/reports/orders/export?format=csv')->assertUnprocessable();
        $this->getJson('/api/reports/nope/export?format=pdf')->assertNotFound();
    }

    public function testQrLinkDownloadsTheReportWithoutSigningIn(): void
    {
        config(['inventory.report_link_url' => 'http://192.168.1.20:8000']);
        $this->bill('arun@example.com', 2, $this->cashier);

        $url = $this->getJson("/api/reports/orders/share-link?format=pdf&period=this_month&employee_id={$this->cashier->id}")
            ->assertOk()
            ->json('url');

        $this->assertStringStartsWith('http://192.168.1.20:8000/api/reports/orders/shared?', $url);
        $this->assertStringContainsString("employee_id={$this->cashier->id}", $url);

        // The phone has no token: the signature alone lets it in.
        $this->app['auth']->forgetGuards();
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->withServerVariables(['HTTP_HOST' => '192.168.1.20:8000'])
            ->get('http://192.168.1.20:8000'.$path)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('order-report-2026-09-01-to-2026-09-15.pdf');

        // Changing any filter breaks the signature.
        $this->get('http://192.168.1.20:8000'.str_replace('period=this_month', 'period=this_year', $path))->assertForbidden();

        // Links expire.
        $this->travel(31)->minutes();
        $this->get('http://192.168.1.20:8000'.$path)->assertForbidden();
    }

    public function testQrLinkWorksBehindAnHttpsProxyLikeRender(): void
    {
        config(['inventory.report_link_url' => 'https://arsha-api.onrender.com']);
        $url = $this->getJson('/api/reports/orders/share-link?format=pdf')->assertOk()->json('url');
        $this->assertStringStartsWith('https://arsha-api.onrender.com/', $url);

        // Render forwards the request as plain http and says https in a header.
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->get(str_replace('https://', 'http://', $url))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function testQrLinkStopsWorkingWhenItsCreatorLosesAccess(): void
    {
        $url = $this->getJson('/api/reports/stock/share-link?format=xlsx')->assertOk()->json('url');
        $this->admin->update(['is_active' => false]);

        $this->get($url)->assertForbidden();
    }

    public function testReportsNeedThePermission(): void
    {
        $this->signIn(['billing.create', 'orders.view']);

        $this->getJson('/api/reports/orders')->assertForbidden();
        $this->getJson('/api/reports/employee-options')->assertForbidden();

        $this->signIn(['reports.view']);

        $this->getJson('/api/reports/orders')->assertOk();
        $this->getJson('/api/reports/employee-options')->assertOk()->assertJsonFragment(['name' => 'Cashier Karthik']);
    }
}
