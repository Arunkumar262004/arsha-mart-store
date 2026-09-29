<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates an order header only; use OrderService to place real orders
 * with lines, totals and stock deduction.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'customer_id' => Customer::factory(),
            'subtotal' => '0.00',
            'tax_total' => '0.00',
            'grand_total' => '0.00',
        ];
    }
}
