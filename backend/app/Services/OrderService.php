<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Jobs\SendOrderConfirmation;
use App\Jobs\SendOrderWhatsAppConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\GstStates;
use App\Support\Money;
use App\Support\Phone;
use App\Support\StoreContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly AccountingService $accounting,
        private readonly DocumentNumberService $numbers,
        private readonly StoreContext $context,
    ) {}

    /**
     * Create a bill: find the customer, check and deduct the store's stock,
     * work out the totals, give it the next invoice number and post the
     * sales voucher, all in one database transaction.
     *
     * The bill belongs to $store, else the request's current store. With
     * payment mode "credit" nothing is collected: the total is owed on the
     * customer's ledger.
     *
     * GST: a sale inside the store's state is taxed as CGST + SGST (half the
     * rate each); with $interstate the whole rate is charged as IGST. A
     * $placeOfSupply (GST state code) different from the store's state code
     * makes the bill interstate automatically.
     *
     * B2B: $customerGstin and $billingAddress are snapshotted on the bill and
     * saved to the customer's record for next time.
     *
     * Items may carry a `unit_price` overriding the product's price. Only
     * internal callers (quotation conversion) pass it; the public bill
     * request never accepts one.
     *
     * Concurrency: the product rows are locked (SELECT ... FOR UPDATE) until
     * the transaction commits, so a second order for the same product waits
     * and then sees the reduced stock. Two orders can never sell the last unit.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price?: string|float|int}>  $items
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
        string $paymentMode = Order::PAYMENT_CASH,
        ?Store $store = null,
        ?string $customerGstin = null,
        ?string $billingAddress = null,
        ?string $placeOfSupply = null,
    ): Order {
        $items = array_values($items);
        $phone = Phone::normalize($phone);
        $store ??= $this->context->store();
        $customerGstin = GstStates::normalizeGstin($customerGstin);
        $billingAddress = filled($billingAddress) ? trim($billingAddress) : null;
        $placeOfSupply = filled($placeOfSupply) ? trim($placeOfSupply) : null;

        if ($customerGstin !== null && ! preg_match(GstStates::GSTIN_PATTERN, $customerGstin)) {
            throw ValidationException::withMessages(['customer_gstin' => 'Enter a valid 15-character GSTIN.']);
        }

        if ($placeOfSupply !== null && filled($store->state_code) && $placeOfSupply !== $store->state_code) {
            $interstate = true;
        }

        if ($paymentMode === Order::PAYMENT_CREDIT && $amountPaid !== null && $amountPaid !== '') {
            throw ValidationException::withMessages([
                'amount_paid' => 'A credit sale is paid later; leave the amount paid empty.',
            ]);
        }

        $b2b = ['customer_gstin' => $customerGstin, 'billing_address' => $billingAddress, 'place_of_supply' => $placeOfSupply];

        $order = DB::transaction(function () use ($email, $name, $items, $amountPaid, $phone, $cashier, $customerId, $updateCustomer, $interstate, $paymentMode, $store, $b2b) {
            $customer = $customerId !== null
                ? $this->useSelectedCustomer($customerId, $updateCustomer, $email, $name, $phone)
                : $this->findOrCreateCustomerByEmail($email, $name, $phone);

            $this->saveBusinessDetails($customer, $b2b);

            $stocks = $this->stock->lock($store, array_column($items, 'product_id'));
            $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');

            $this->checkStockIsAvailable($items, $products, $stocks);

            $lines = $this->calculateLineTotals($items, $products, $interstate);

            $order = $this->saveOrder($customer, $lines, $amountPaid, $cashier, $interstate, $paymentMode, $store, $b2b);

            $this->deductStock($order, $lines, $cashier, $store);

            $this->accounting->postSale($order, $cashier);

            return $order;
        }, attempts: 3);

        $order->load(['customer', 'cashier', 'items.product', 'store']);

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
     * A GSTIN, billing address or place of supply typed on a bill is kept on
     * the customer so the next bill auto-fills it.
     *
     * @param  array{customer_gstin: ?string, billing_address: ?string, place_of_supply: ?string}  $b2b
     */
    private function saveBusinessDetails(Customer $customer, array $b2b): void
    {
        $changes = array_filter([
            'gstin' => $b2b['customer_gstin'],
            'address' => $b2b['billing_address'],
            'state_code' => $b2b['place_of_supply'],
            'state' => GstStates::name($b2b['place_of_supply']),
        ], fn ($value) => $value !== null);

        if ($changes !== []) {
            $customer->update($changes);
        }
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
     * Reject the whole order if any line asks for more than the store has.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductStock>  $stocks  locked by StockService::lock
     *
     * @throws InsufficientStockException
     */
    private function checkStockIsAvailable(array $items, Collection $products, Collection $stocks): void
    {
        $shortages = [];

        foreach ($items as $index => $item) {
            $product = $products->get((int) $item['product_id']);
            $available = $stocks->get((int) $item['product_id'])?->stock ?? 0;

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
     * @param  array<int, array{product_id: int, quantity: int, unit_price?: string|float|int}>  $items
     * @param  Collection<int, Product>  $products
     * @return array<int, array{product: Product, quantity: int, unit_price: string, subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}>
     */
    private function calculateLineTotals(array $items, Collection $products, bool $interstate): array
    {
        return array_map(function (array $item) use ($products, $interstate) {
            $product = $products->get((int) $item['product_id']);
            $quantity = (int) $item['quantity'];
            $priceCents = Money::toCents($item['unit_price'] ?? $product->price);

            return [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => Money::format($priceCents),
                ...GstCalculator::line($priceCents, $quantity, $product->tax_percent, $interstate),
            ];
        }, $items);
    }

    /**
     * Save the order and its lines. Each line keeps the price and tax rate
     * at the time of sale, so later price changes don't alter old bills.
     *
     * @param  array<int, array{product: Product, quantity: int, unit_price: string, subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}>  $lines
     * @param  array{customer_gstin: ?string, billing_address: ?string, place_of_supply: ?string}  $b2b
     */
    private function saveOrder(Customer $customer, array $lines, string|float|null $amountPaid, ?User $cashier, bool $interstate, string $paymentMode, Store $store, array $b2b): Order
    {
        $totals = GstCalculator::totals($lines);

        [$paid, $change] = $this->calculateChange($amountPaid, Money::toCents($totals['grand_total']));

        $order = $customer->orders()->create([
            'store_id' => $store->id,
            'order_number' => $this->generateOrderNumber(),
            'invoice_number' => $this->numbers->next('invoice', $store),
            'payment_mode' => $paymentMode,
            ...$totals,
            'is_interstate' => $interstate,
            ...$b2b,
            'amount_paid' => $paid,
            'change_due' => $change,
            'created_by' => $cashier?->id,
            'created_by_name' => $cashier?->name,
        ]);

        $order->items()->createMany(array_map(fn (array $line) => [
            'product_id' => $line['product']->id,
            'unit_price' => $line['unit_price'],
            'tax_percent' => $line['product']->tax_percent,
            'quantity' => $line['quantity'],
            ...GstCalculator::lineColumns($line),
        ], $lines));

        return $order;
    }

    /**
     * Reduce the store's stock and record the sale in the stock log.
     *
     * @param  array<int, array{product: Product, quantity: int, subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}>  $lines
     */
    private function deductStock(Order $order, array $lines, ?User $cashier, Store $store): void
    {
        $this->stock->moveMany(
            $store,
            array_map(fn (array $line) => ['product_id' => $line['product']->id, 'quantity' => -$line['quantity']], $lines),
            StockMovement::TYPE_SALE,
            $cashier,
            $order->order_number,
            order: $order,
        );
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
