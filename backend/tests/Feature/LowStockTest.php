<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();

        Product::factory()->create(['name' => 'Eggs', 'stock' => 2]);
        Product::factory()->create(['name' => 'Bread', 'stock' => 4]);
        Product::factory()->create(['name' => 'Milk', 'stock' => 10]);
        Product::factory()->create(['name' => 'Rice', 'stock' => 50]);
    }

    public function testItUsesTheConfiguredThresholdByDefault(): void
    {
        config(['inventory.low_stock_threshold' => 10]);

        // "Below" is strict: Milk at exactly 10 is not low.
        $this->getJson('/api/products/low-stock')
            ->assertOk()
            ->assertJsonPath('meta.threshold', 10)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Eggs')
            ->assertJsonPath('data.1.name', 'Bread');
    }

    public function testThresholdCanBeOverriddenPerRequest(): void
    {
        $this->getJson('/api/products/low-stock?threshold=11')
            ->assertOk()
            ->assertJsonPath('meta.threshold', 11)
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/products/low-stock?threshold=0')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function testInvalidThresholdIsRejected(): void
    {
        $this->getJson('/api/products/low-stock?threshold=-1')->assertUnprocessable();
        $this->getJson('/api/products/low-stock?threshold=abc')->assertUnprocessable();
    }
}
