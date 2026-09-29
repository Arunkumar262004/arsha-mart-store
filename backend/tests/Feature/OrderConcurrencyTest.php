<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Races real OS processes against the same database row. Each process runs
 * `php artisan orders:place`, i.e. the same OrderService the API uses.
 *
 * To guarantee the requests genuinely overlap (rather than happening to run
 * one after another), the test first holds a row lock on the product itself,
 * starts every process so they all queue up behind that lock, then releases
 * it and lets them fight for the stock at the same instant.
 */
class OrderConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private bool $supported = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supported = DB::connection()->getDriverName() === 'pgsql';

        if (! $this->supported) {
            $this->markTestSkipped('Row-level locking needs PostgreSQL; SQLite ignores SELECT ... FOR UPDATE.');
        }
    }

    protected function tearDown(): void
    {
        // Committed data would otherwise leak into RefreshDatabase tests.
        if ($this->supported) {
            Schema::withoutForeignKeyConstraints(function () {
                foreach (['order_items', 'orders', 'customers', 'products', 'jobs'] as $table) {
                    DB::table($table)->truncate();
                }
            });
        }

        parent::tearDown();
    }

    public function testOnlyOneOfTwoSimultaneousOrdersGetsTheLastUnit(): void
    {
        $product = Product::factory()->withStock(1)->create();

        $results = $this->race($product, buyers: 2);

        $this->assertSame(1, $results['placed'], 'Exactly one order must succeed.');
        $this->assertSame(1, $results['rejected'], 'The other must fail cleanly.');
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
    }

    public function testManySimultaneousOrdersNeverOversell(): void
    {
        $product = Product::factory()->withStock(3)->create();

        $results = $this->race($product, buyers: 6);

        $this->assertSame(3, $results['placed']);
        $this->assertSame(3, $results['rejected']);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(3, (int) DB::table('order_items')->where('product_id', $product->id)->sum('quantity'));
    }

    /**
     * @return array{placed: int, rejected: int}
     */
    private function race(Product $product, int $buyers): array
    {
        $connection = DB::getDefaultConnection();
        $db = config("database.connections.{$connection}");

        // Child processes must hit the same test database as this process.
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'],
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'WASENDER_API_TOKEN' => '',
        ];

        DB::beginTransaction();
        Product::whereKey($product->id)->lockForUpdate()->first();

        $processes = [];
        for ($i = 1; $i <= $buyers; $i++) {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'orders:place', "buyer{$i}@example.com", (string) $product->id, '1', "--name=Buyer {$i}"],
                base_path(),
                $env,
                timeout: 60,
            );
            $process->start();
            $processes[] = $process;
        }

        // Give every process time to boot and block on the row lock.
        usleep(2_500_000);
        DB::commit();

        $results = ['placed' => 0, 'rejected' => 0];
        foreach ($processes as $process) {
            $process->wait();
            $output = json_decode(trim($process->getOutput()), true);

            $this->assertIsArray($output, 'Unexpected process output: '.$process->getOutput().$process->getErrorOutput());
            $results[$output['status']]++;
        }

        return $results;
    }
}
