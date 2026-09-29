<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Jobs\SendOrderConfirmation;
use App\Jobs\SendOrderWhatsAppConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Create a bill: find the customer, check and deduct stock, work out the
     * totals and save everything in one database transaction.
     *
     * GST: a sale inside the store's state is taxed as CGST + SGST (half the
     * rate each); with $interstate the whole rate is charged as IGST.
     *
     * Concurrency: the product rows are locked (SELECT ... FOR UPDATE) until
     * the transaction commits, so a second order for the same product waits
     * and then sees the reduced stock. Two orders can never sell the last unit.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     *
     * @throws InsufficientStockException
     * @throws ValidationException
     */
    public function placeOrder(
        string $email,
        ?string $name,
        array $items,
        string|float|null $amountPaid = null,
        ?string $phone = null,
        ?User $cashier = null,
        ?int $customerId = null,
        bool $updateCustomer = false,
        bool $interstate = false,
    ): Order {
        $items = array_values($items);
        $phone = Phone::normalize($phone);

        $order = DB::transaction(function () use ($email, $name, $items, $amountPaid, $phone, $cashier, $customerId, $updateCustomer, $interstate) {
            $customer = $customerId !== null
                ? $this->useSelectedCustomer($customerId, $updateCustomer, $email, $name, $phone)
                : $this->findOrCreateCustomerByEmail($email, $name, $phone);

            $products = $this->lockProducts($items);

            $this->checkStockIsAvailable($items, $products);

            $lines = $this->calculateLineTotals($items, $products, $interstate);

            $order = $this->saveOrder($customer, $lines, $amountPaid, $cashier, $interstate);

            $this->deductStock($order, $lines, $cashier);

            return $order;
        }, attempts: 3);

        $order->load(['customer', 'cashier', 'items.product']);

        $this->queueConfirmations($order);

        return $order;
    }

    /**
     * The cashier picked an existing customer (e.g. found by mobile number).
     * With $update, the details typed on the bill are saved to their record
     * first, which covers a returning customer whose email has changed.
     */
    private function useSelectedCustomer(int $customerId, bool $update, string $email, ?string $name, ?string $phone): Customer
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

        if ($update) {
            $customer->update(array_filter([
                'email' => mb_strtolower(trim($email)),
                'name' => filled($name) ? trim($name) : null,
                'phone' => $phone,
            ], fn ($value) => $value !== null));
        }

        return $customer;
    }

    /**
     * Customers are identified by email. A name is needed only for a new
     * customer. A mobile number, if given, is saved on the customer.
     */
    private function findOrCreateCustomerByEmail(string $email, ?string $name, ?string $phone): Customer
    {
        $email = mb_strtolower(trim($email));

        if ($phone !== null && Customer::where('phone', $phone)->where('email', '!=', $email)->exists()) {
            throw ValidationException::withMessages([
                'customer_phone' => 'This mobile number belongs to another customer.',
            ]);
        }

        $customer = Customer::query()->where('email', $email)->first();

        if ($customer) {
            if ($phone !== null && $customer->phone !== $phone) {
                $customer->update(['phone' => $phone]);
            }

            return $customer;
        }

        if (blank($name)) {
            throw ValidationException::withMessages([
                'customer_name' => 'A name is required for a new customer.',
            ]);
        }

        // createOrFirst handles two first-time orders for the same email
        // arriving together: the second one reads the row the first created.
        return Customer::createOrFirst(['email' => $email], ['name' => trim($name), 'phone' => $phone]);
    }

    /**
     * Lock the ordered products until the transaction ends. Locking in id
     * order means two orders for the same products always lock them in the
     * same sequence, so they can't deadlock each other.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return Collection<int, Product>
     */
    private function lockProducts(array $items): Collection
    {
        return Product::query()
            ->whereKey(array_column($items, 'product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Reject the whole order if any line asks for more than is in stock.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @param  Collection<int, Product>  $products
     *
     * @throws InsufficientStockException
     */
    private function checkStockIsAvailable(array $items, Collection $products): void
    {
        $shortages = [];

        foreach ($items as $index => $item) {
            $product = $products->get((int) $item['product_id']);
            $available = $product?->stock ?? 0;

            if ($available < (int) $item['quantity']) {
                $shortages[] = [
                    'index' => $index,
                    'product_id' => (int) $item['product_id'],
                    'name' => $product?->name ?? 'Unknown product',
                    'requested' => (int) $item['quantity'],
                    'available' => $available,
                ];
            }
        }

        if ($shortages !== []) {
            throw new InsufficientStockException($shortages);
        }
    }

    /**
     * Price and GST for each line, in paise (integers) to avoid rounding errors.
     * Within the state, CGST and SGST are each worked out at half the rate;
     * across states, IGST is the full rate.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @param  Collection<int, Product>  $products
     * @return array<int, array{product: Product, quantity: int, subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}>
     */
    private function calculateLineTotals(array $items, Collection $products, bool $interstate): array
    {
        return array_map(function (array $item) use ($products, $interstate) {
            $product = $products->get((int) $item['product_id']);
            $quantity = (int) $item['quantity'];
            $subtotal = Money::toCents($product->price) * $quantity;
            $rate = (float) $product->tax_percent;

            $gst = $interstate
                ? [
                    'cgst_percent' => 0, 'cgst_cents' => 0,
                    'sgst_percent' => 0, 'sgst_cents' => 0,
                    'igst_percent' => $rate, 'igst_cents' => Money::taxOn($subtotal, $rate),
                ]
                : [
                    'cgst_percent' => $rate / 2, 'cgst_cents' => Money::taxOn($subtotal, $rate / 2),
                    'sgst_percent' => $rate / 2, 'sgst_cents' => Money::taxOn($subtotal, $rate / 2),
                    'igst_percent' => 0, 'igst_cents' => 0,
                ];

            return [
                'product' => $product,
                'quantity' => $quantity,
                'subtotal_cents' => $subtotal,
                'tax_cents' => $gst['cgst_cents'] + $gst['sgst_cents'] + $gst['igst_cents'],
                'gst' => $gst,
            ];
        }, $items);
    }

    /**
     * Save the order and its lines. Each line keeps the price and tax rate
     * at the time of sale, so later price changes don't alter old bills.
     *
     * @param  array<int, array{product: Product, quantity: int, subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}>  $lines
     */
    private function saveOrder(Customer $customer, array $lines, string|float|null $amountPaid, ?User $cashier, bool $interstate): Order
    {
        $subtotal = array_sum(array_column($lines, 'subtotal_cents'));
        $tax = array_sum(array_column($lines, 'tax_cents'));
        $grandTotal = $subtotal + $tax;
        $sumOf = fn (string $key) => Money::format(array_sum(array_map(fn (array $line) => $line['gst'][$key], $lines)));

        [$paid, $change] = $this->calculateChange($amountPaid, $grandTotal);

        $order = $customer->orders()->create([
            'order_number' => $this->generateOrderNumber(),
            'subtotal' => Money::format($subtotal),
            'tax_total' => Money::format($tax),
            'is_interstate' => $interstate,
            'cgst_amount' => $sumOf('cgst_cents'),
            'sgst_amount' => $sumOf('sgst_cents'),
            'igst_amount' => $sumOf('igst_cents'),
            'grand_total' => Money::format($grandTotal),
            'amount_paid' => $paid,
            'change_due' => $change,
            'created_by' => $cashier?->id,
            'created_by_name' => $cashier?->name,
        ]);

        $order->items()->createMany(array_map(fn (array $line) => [
            'product_id' => $line['product']->id,
            'unit_price' => $line['product']->price,
            'tax_percent' => $line['product']->tax_percent,
            'quantity' => $line['quantity'],
            'line_subtotal' => Money::format($line['subtotal_cents']),
            'line_tax' => Money::format($line['tax_cents']),
            'cgst_percent' => $line['gst']['cgst_percent'],
            'cgst_amount' => Money::format($line['gst']['cgst_cents']),
            'sgst_percent' => $line['gst']['sgst_percent'],
            'sgst_amount' => Money::format($line['gst']['sgst_cents']),
            'igst_percent' => $line['gst']['igst_percent'],
            'igst_amount' => Money::format($line['gst']['igst_cents']),
            'line_total' => Money::format($line['subtotal_cents'] + $line['tax_cents']),
        ], $lines));

        return $order;
    }

    /**
     * Reduce each product's stock and record the sale in the stock log.
     *
     * @param  array<int, array{product: Product, quantity: int, subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}>  $lines
     */
    private function deductStock(Order $order, array $lines, ?User $cashier): void
    {
        foreach ($lines as $line) {
            $line['product']->decrement('stock', $line['quantity']);

            $line['product']->stockMovements()->create([
                'user_id' => $cashier?->id,
                'user_name' => $cashier?->name,
                'order_id' => $order->id,
                'type' => StockMovement::TYPE_SALE,
                'quantity' => -$line['quantity'],
                'stock_after' => $line['product']->stock,
                'note' => $order->order_number,
            ]);
        }
    }

    /**
     * Queue the email (and WhatsApp, if there's a mobile number). This runs
     * after the transaction has committed, so a failed order never notifies anyone.
     */
    private function queueConfirmations(Order $order): void
    {
        SendOrderConfirmation::dispatch($order);

        if ($order->customer->phone !== null) {
            SendOrderWhatsAppConfirmation::dispatch($order);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} amount paid and change to return
     */
    private function calculateChange(string|float|null $amountPaid, int $grandTotal): array
    {
        if ($amountPaid === null || $amountPaid === '') {
            return [null, null];
        }

        $paid = Money::toCents($amountPaid);

        if ($paid < $grandTotal) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Amount paid ('.Money::format($paid).') is less than the grand total ('.Money::format($grandTotal).').',
            ]);
        }

        return [Money::format($paid), Money::format($paid - $grandTotal)];
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    }
}
