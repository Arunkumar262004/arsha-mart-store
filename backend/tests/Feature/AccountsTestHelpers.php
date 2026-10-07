<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;

/**
 * Shared set-up for the accounts module tests: place real bills through the
 * API and post vouchers straight through AccountingService.
 */
trait AccountsTestHelpers
{
    /**
     * Place a bill for the given lines; returns the created order data.
     *
     * @param  list<array{0: Product, 1: int}>  $lines
     * @return array<string, mixed>
     */
    protected function placeBill(array $lines, array $payload = [], array $headers = []): array
    {
        return $this->postJson('/api/orders', [
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer',
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            ...$payload,
        ], $headers)->assertCreated()->json('data');
    }

    /**
     * @param  list<array{0: mixed, 1: string|int|float, 2: string|int|float}>  $lines
     */
    protected function postVoucher(string $type, array $lines, ?string $date = null, ?Store $store = null, ?string $narration = null): Voucher
    {
        return app(AccountingService::class)->post(
            $type,
            $lines,
            $store ?? Store::main(),
            $date ? Carbon::parse($date) : null,
            $narration,
        );
    }
}
