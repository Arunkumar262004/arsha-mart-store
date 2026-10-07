<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Support\GstStates;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quotations: priced offers that don't touch stock or the books until they
 * are converted into a bill, which then keeps the quoted prices.
 */
class QuotationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly OrderService $orders,
    ) {}

    /**
     * Create a quotation at $store.
     *
     * @param  array<string, mixed>  $data  validated SaveQuotationRequest data
     */
    public function create(array $data, Store $store, ?User $user): Quotation
    {
        return DB::transaction(function () use ($data, $store, $user) {
            $date = Carbon::parse($data['date'] ?? today());

            $quotation = new Quotation([
                'store_id' => $store->id,
                'number' => $this->numbers->next('quotation', $store, $date),
                'status' => Quotation::STATUS_DRAFT,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name,
            ]);

            return $this->fill($quotation, $data);
        });
    }

    /**
     * Replace a quotation's details and lines (not once converted or cancelled).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        return DB::transaction(function () use ($quotation, $data) {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $this->ensureOpen($quotation);

            $quotation->items()->delete();

            return $this->fill($quotation, $data);
        });
    }

    /**
     * Turn the quotation into a bill at its store with the quoted prices. The
     * bill goes through OrderService::placeOrder (stock check and deduction,
     * invoice number, sales voucher) in the same transaction that marks the
     * quotation converted, so it can only ever be converted once.
     *
     * @param  array{payment_mode?: ?string, amount_paid?: string|float|null, customer_email?: ?string, customer_name?: ?string}  $data
     *
     * @throws InsufficientStockException
     * @throws ValidationException
     */
    public function convert(Quotation $quotation, array $data, ?User $user): Order
    {
        return DB::transaction(function () use ($quotation, $data, $user) {
            $quotation = Quotation::query()->with(['items', 'store', 'customer'])->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->status === Quotation::STATUS_CONVERTED) {
                throw ValidationException::withMessages(['quotation' => "This quotation is already converted to bill {$quotation->convertedOrder?->invoice_number}."]);
            }
            $this->ensureOpen($quotation);

            $email = $quotation->customer?->email ?? $quotation->customer_email ?? ($data['customer_email'] ?? null);
            if (blank($email)) {
                throw ValidationException::withMessages(['customer_email' => 'A customer email is needed to make the bill.']);
            }

            $order = $this->orders->placeOrder(
                email: $email,
                name: ($data['customer_name'] ?? null) ?: $quotation->customer_name,
                // Line indexes match the quotation's, so stock errors point at the right line.
                items: $quotation->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                ])->all(),
                amountPaid: $data['amount_paid'] ?? null,
                cashier: $user,
                customerId: $quotation->customer_id,
                interstate: $quotation->is_interstate,
                paymentMode: ($data['payment_mode'] ?? null) ?: Order::PAYMENT_CASH,
                store: $quotation->store,
                customerGstin: $quotation->customer_gstin,
            );

            $quotation->update([
                'status' => Quotation::STATUS_CONVERTED,
                'converted_order_id' => $order->id,
                'customer_id' => $order->customer_id,
            ]);

            return $order;
        });
    }

    public function ensureOpen(Quotation $quotation): void
    {
        if ($quotation->isClosed()) {
            throw ValidationException::withMessages(['quotation' => "A {$quotation->status} quotation can no longer be changed."]);
        }
    }

    /**
     * Save the header fields and price every line. A line's price defaults
     * to the product's current price; the tax rate is always the product's.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Quotation $quotation, array $data): Quotation
    {
        $customer = $this->matchCustomer($data);
        $interstate = (bool) ($data['is_interstate'] ?? false);

        $items = array_values($data['items']);
        $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');

        $lines = array_map(function (array $item) use ($products, $interstate) {
            $product = $products->get((int) $item['product_id']);
            $price = isset($item['unit_price']) && $item['unit_price'] !== '' ? $item['unit_price'] : $product->price;

            return [
                'product' => $product,
                'quantity' => (int) $item['quantity'],
                'unit_price' => Money::format(Money::toCents($price)),
                ...GstCalculator::line(Money::toCents($price), (int) $item['quantity'], $product->tax_percent, $interstate),
            ];
        }, $items);

        $quotation->fill([
            'customer_id' => $customer?->id,
            'customer_name' => trim($data['customer_name'] ?? '') ?: $customer?->name,
            'customer_email' => filled($data['customer_email'] ?? null) ? mb_strtolower(trim($data['customer_email'])) : $customer?->email,
            'customer_phone' => ($data['customer_phone'] ?? null) ?: $customer?->phone,
            'customer_gstin' => GstStates::normalizeGstin($data['customer_gstin'] ?? null) ?? $customer?->gstin,
            'date' => $data['date'] ?? ($quotation->date ?? today()),
            'valid_until' => $data['valid_until'] ?? null,
            'is_interstate' => $interstate,
            'notes' => $data['notes'] ?? null,
            ...GstCalculator::totals($lines),
        ])->save();

        $quotation->items()->createMany(array_map(fn (array $line) => [
            'product_id' => $line['product']->id,
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'tax_percent' => $line['product']->tax_percent,
            ...GstCalculator::lineColumns($line),
        ], $lines));

        return $quotation->load(['items.product', 'store']);
    }

    /**
     * The customer the quotation is for: the chosen one, else an existing
     * customer with the typed email. New customers are only created when the
     * quotation becomes a bill.
     *
     * @param  array<string, mixed>  $data
     */
    private function matchCustomer(array $data): ?Customer
    {
        if (filled($data['customer_id'] ?? null)) {
            return Customer::find($data['customer_id']);
        }

        if (filled($data['customer_email'] ?? null)) {
            return Customer::where('email', mb_strtolower(trim($data['customer_email'])))->first();
        }

        return null;
    }
}
