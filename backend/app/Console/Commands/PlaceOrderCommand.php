<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientStockException;
use App\Services\OrderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Places a single-line order from the command line. The concurrency test
 * launches several of these as separate OS processes to race for stock.
 */
#[Signature('orders:place {email} {product_id} {quantity} {--name=CLI Customer}')]
#[Description('Place a single-product order through OrderService')]
class PlaceOrderCommand extends Command
{
    public function handle(OrderService $orders): int
    {
        try {
            $order = $orders->placeOrder(
                email: $this->argument('email'),
                name: $this->option('name'),
                items: [[
                    'product_id' => (int) $this->argument('product_id'),
                    'quantity' => (int) $this->argument('quantity'),
                ]],
            );
        } catch (InsufficientStockException|ValidationException $e) {
            $this->line(json_encode(['status' => 'rejected', 'reason' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->line(json_encode(['status' => 'placed', 'order_number' => $order->order_number]));

        return self::SUCCESS;
    }
}
