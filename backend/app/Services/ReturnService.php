<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Concerns\PostsDocuments;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase returns (debit notes: goods back to a supplier) and sales
 * returns (credit notes: goods back from a customer).
 */
class ReturnService
{
    use PostsDocuments;

    public function __construct(
        private readonly StockService $stock,
        private readonly AccountingService $accounting,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Send goods back to a supplier. Linked to a purchase, the lines are
     * priced from that purchase and limited to what is still unreturned;
     * the return then happens at the purchase's store. Without a purchase,
     * the cost is given per line and the return happens at $store.
     *
     * Debit note: Dr Supplier (refund_mode credit) or Cash / Bank,
     *             Cr Purchase Returns (taxable), Cr Input CGST / SGST / IGST.
     *
     * @param  array{supplier_id: int, purchase_id?: ?int, date?: ?string, reason?: ?string, refund_mode: string,
     *     is_interstate?: ?bool, items: list<array{product_id: int, quantity: int, unit_cost?: string|float|null, tax_percent?: string|float|null}>}  $data
     */
    public function createPurchaseReturn(array $data, Store $store, ?User $user): PurchaseReturn
    {
        $date = Carbon::parse($data['date'] ?? now())->startOfDay();
        $items = array_values($data['items']);

        return DB::transaction(function () use ($data, $store, $user, $date, $items) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $purchase = null;

            if (! empty($data['purchase_id'])) {
                // Locked so two returns against one purchase cannot both pass the limit.
                $purchase = Purchase::query()->lockForUpdate()->with(['items', 'store'])->findOrFail($data['purchase_id']);

                if ($purchase->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['purchase_id' => 'This purchase is from a different supplier.']);
                }
                if ($purchase->isCancelled()) {
                    throw ValidationException::withMessages(['purchase_id' => 'This purchase is cancelled.']);
                }

                $store = $purchase->store;
                $interstate = $purchase->is_interstate;
            } else {
                $interstate = $data['is_interstate'] ?? PurchaseService::isInterstate($supplier, $store);
            }

            $lines = $purchase
                ? $this->linesFromPurchase($purchase, $items, $interstate)
                : $this->linesFromInput($items, $interstate);

            $totals = $this->sumLines($lines);

            $return = PurchaseReturn::create([
                'store_id' => $store->id,
                'number' => $this->numbers->next('purchase_return', $store, $date),
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchase?->id,
                'date' => $date->toDateString(),
                'reason' => $data['reason'] ?? null,
                'is_interstate' => $interstate,
                'subtotal' => Money::format($totals['subtotal']),
                'cgst_amount' => Money::format($totals['cgst']),
                'sgst_amount' => Money::format($totals['sgst']),
                'igst_amount' => Money::format($totals['igst']),
                'tax_total' => Money::format($totals['tax']),
                'grand_total' => Money::format($totals['total']),
                'refund_mode' => $data['refund_mode'],
                'created_by' => $user?->id,
                'created_by_name' => $user?->name,
            ]);

            $return->items()->createMany(array_map(fn (array $line) => [
                'purchase_item_id' => $line['purchase_item_id'],
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'unit_cost' => Money::format($line['unit_cents']),
                'tax_percent' => $line['rate'],
                ...$this->lineAmounts($line),
            ], $lines));

            $this->stock->moveMany(
                $store,
                array_map(fn (array $line) => ['product_id' => $line['product_id'], 'quantity' => -$line['quantity']], $lines),
                StockMovement::TYPE_PURCHASE_RETURN,
                $user,
                $return->number,
                $return,
            );

            $debit = match ($data['refund_mode']) {
                'cash' => Account::CASH,
                'bank' => Account::BANK,
                default => Account::forParty($supplier),
            };

            $this->accounting->post(Voucher::DEBIT_NOTE, [
                [$debit, Money::format($totals['total']), 0],
                [Account::PURCHASE_RETURNS, 0, Money::format($totals['subtotal'])],
                [Account::INPUT_CGST, 0, Money::format($totals['cgst'])],
                [Account::INPUT_SGST, 0, Money::format($totals['sgst'])],
                [Account::INPUT_IGST, 0, Money::format($totals['igst'])],
            ], $store, $date, mb_substr("Purchase return {$return->number} to {$supplier->name}", 0, 255), $return, $user, $return->number);

            return $return;
        });
    }

    /**
     * Quantities still returnable per product of a purchase.
     *
     * @return Collection<int, int> keyed by product id
     */
    public function returnableForPurchase(Purchase $purchase): Collection
    {
        $returned = PurchaseReturnItem::query()
            ->whereHas('purchaseReturn', fn ($q) => $q->where('purchase_id', $purchase->id))
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => (int) $rows->sum('quantity'));

        return $purchase->items
            ->groupBy('product_id')
            ->map(fn (Collection $rows, int $productId) => (int) $rows->sum('quantity') - ($returned[$productId] ?? 0));
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return list<array<string, mixed>>
     */
    private function linesFromPurchase(Purchase $purchase, array $items, bool $interstate): array
    {
        $returnable = $this->returnableForPurchase($purchase);
        $requested = [];
        $errors = [];
        $lines = [];

        foreach ($items as $index => $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];
            $source = $purchase->items->firstWhere('product_id', $productId);

            if (! $source) {
                $errors["items.{$index}.product_id"] = 'This product is not on the purchase.';

                continue;
            }

            $requested[$productId] = ($requested[$productId] ?? 0) + $quantity;
            if ($requested[$productId] > ($returnable[$productId] ?? 0)) {
                $errors["items.{$index}.quantity"] = 'Only '.max($returnable[$productId] ?? 0, 0).' unit(s) of this product can still be returned.';

                continue;
            }

            $unit = Money::toCents($source->unit_cost);
            $subtotal = $unit * $quantity;
            $lines[] = [
                'purchase_item_id' => $source->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_cents' => $unit,
                'rate' => (float) $source->tax_percent,
                'subtotal' => $subtotal,
                'gst' => $this->gstSplit($subtotal, (float) $source->tax_percent, $interstate),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * @param  list<array{product_id: int, quantity: int, unit_cost?: string|float|null, tax_percent?: string|float|null}>  $items
     * @return list<array<string, mixed>>
     */
    private function linesFromInput(array $items, bool $interstate): array
    {
        $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');
        $errors = [];
        $lines = [];

        foreach ($items as $index => $item) {
            $product = $products->get((int) $item['product_id']);
            $cost = $item['unit_cost'] ?? $product->cost_price;

            if ($cost === null || $cost === '') {
                $errors["items.{$index}.unit_cost"] = 'Enter the cost of this product.';

                continue;
            }

            $rate = (float) ($item['tax_percent'] ?? $product->tax_percent);
            $unit = Money::toCents($cost);
            $subtotal = $unit * (int) $item['quantity'];
            $lines[] = [
                'purchase_item_id' => null,
                'product_id' => $product->id,
                'quantity' => (int) $item['quantity'],
                'unit_cents' => $unit,
                'rate' => $rate,
                'subtotal' => $subtotal,
                'gst' => $this->gstSplit($subtotal, $rate, $interstate),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * Take back goods a customer returns against a bill. Prices and GST
     * rates come from the original bill lines; the stock goes back to the
     * bill's store. GST is prorated by quantity (Money::taxOn on the line's
     * taxable value), and the return that completes a line takes exactly
     * what is left, so returning everything reverses the bill to the paisa.
     *
     * Credit note: Dr Sales Returns (taxable), Dr Output CGST / SGST / IGST,
     *              Cr Cash / Bank / Customer ledger (refund_mode credit).
     *
     * @param  array{order_id: int, date?: ?string, reason?: ?string, refund_mode: string,
     *     items: list<array{order_item_id: int, quantity: int}>}  $data
     */
    public function createSalesReturn(array $data, ?User $user): SalesReturn
    {
        $date = Carbon::parse($data['date'] ?? now())->startOfDay();
        $items = array_values($data['items']);

        return DB::transaction(function () use ($data, $user, $date, $items) {
            // Locked so two returns against one bill cannot both pass the limit.
            $order = Order::query()->lockForUpdate()->with(['items', 'store', 'customer'])->findOrFail($data['order_id']);
            $returned = $this->returnedForOrder($order);

            $errors = [];
            $lines = [];
            foreach ($items as $index => $item) {
                /** @var OrderItem|null $sold */
                $sold = $order->items->firstWhere('id', (int) $item['order_item_id']);
                if (! $sold) {
                    $errors["items.{$index}.order_item_id"] = 'This line is not on the bill.';

                    continue;
                }

                $quantity = (int) $item['quantity'];
                $before = $returned[$sold->id] ?? ['quantity' => 0, 'subtotal' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
                $left = $sold->quantity - $before['quantity'];

                if ($quantity > $left) {
                    $errors["items.{$index}.quantity"] = "Only {$left} unit(s) of this line can still be returned.";

                    continue;
                }

                if ($quantity === $left) {
                    // Last units: whatever remains, so the totals match the bill exactly.
                    $subtotal = Money::toCents($sold->line_subtotal) - $before['subtotal'];
                    $gst = [
                        'cgst' => Money::toCents($sold->cgst_amount) - $before['cgst'],
                        'sgst' => Money::toCents($sold->sgst_amount) - $before['sgst'],
                        'igst' => Money::toCents($sold->igst_amount) - $before['igst'],
                    ];
                } else {
                    $subtotal = Money::toCents($sold->unit_price) * $quantity;
                    $gst = [
                        'cgst' => Money::taxOn($subtotal, $sold->cgst_percent),
                        'sgst' => Money::taxOn($subtotal, $sold->sgst_percent),
                        'igst' => Money::taxOn($subtotal, $sold->igst_percent),
                    ];
                }

                $lines[] = [
                    'order_item_id' => $sold->id,
                    'product_id' => $sold->product_id,
                    'quantity' => $quantity,
                    'unit_price' => $sold->unit_price,
                    'rate' => (float) $sold->tax_percent,
                    'subtotal' => $subtotal,
                    'gst' => $gst,
                ];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $totals = $this->sumLines($lines);
            $store = $order->store;

            $return = SalesReturn::create([
                'store_id' => $store->id,
                'number' => $this->numbers->next('sales_return', $store, $date),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'date' => $date->toDateString(),
                'reason' => $data['reason'] ?? null,
                'subtotal' => Money::format($totals['subtotal']),
                'cgst_amount' => Money::format($totals['cgst']),
                'sgst_amount' => Money::format($totals['sgst']),
                'igst_amount' => Money::format($totals['igst']),
                'tax_total' => Money::format($totals['tax']),
                'grand_total' => Money::format($totals['total']),
                'refund_mode' => $data['refund_mode'],
                'created_by' => $user?->id,
                'created_by_name' => $user?->name,
            ]);

            $return->items()->createMany(array_map(fn (array $line) => [
                'order_item_id' => $line['order_item_id'],
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_percent' => $line['rate'],
                ...$this->lineAmounts($line),
            ], $lines));

            $this->stock->moveMany(
                $store,
                array_map(fn (array $line) => ['product_id' => $line['product_id'], 'quantity' => $line['quantity']], $lines),
                StockMovement::TYPE_SALE_RETURN,
                $user,
                "{$return->number} against {$order->invoice_number}",
                $return,
            );

            $credit = match ($data['refund_mode']) {
                'bank' => Account::BANK,
                'credit' => Account::forParty($order->customer),
                default => Account::CASH,
            };

            $this->accounting->post(Voucher::CREDIT_NOTE, [
                [Account::SALES_RETURNS, Money::format($totals['subtotal']), 0],
                [Account::OUTPUT_CGST, Money::format($totals['cgst']), 0],
                [Account::OUTPUT_SGST, Money::format($totals['sgst']), 0],
                [Account::OUTPUT_IGST, Money::format($totals['igst']), 0],
                [$credit, 0, Money::format($totals['total'])],
            ], $store, $date, "Sales return {$return->number} against {$order->invoice_number} ({$data['refund_mode']})", $return, $user, $return->number);

            return $return;
        });
    }

    /**
     * What has already been returned per bill line, in cents.
     *
     * @return array<int, array{quantity: int, subtotal: int, cgst: int, sgst: int, igst: int}> keyed by order item id
     */
    public function returnedForOrder(Order $order): array
    {
        $rows = SalesReturnItem::query()
            ->whereIn('order_item_id', $order->items->pluck('id'))
            ->get();

        $returned = [];
        foreach ($rows as $row) {
            $sum = $returned[$row->order_item_id] ?? ['quantity' => 0, 'subtotal' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $returned[$row->order_item_id] = [
                'quantity' => $sum['quantity'] + $row->quantity,
                'subtotal' => $sum['subtotal'] + Money::toCents($row->line_subtotal),
                'cgst' => $sum['cgst'] + Money::toCents($row->cgst_amount),
                'sgst' => $sum['sgst'] + Money::toCents($row->sgst_amount),
                'igst' => $sum['igst'] + Money::toCents($row->igst_amount),
            ];
        }

        return $returned;
    }

    /**
     * @param  list<array{subtotal: int, gst: array{cgst: int, sgst: int, igst: int}}>  $lines
     * @return array{subtotal: int, cgst: int, sgst: int, igst: int, tax: int, total: int}
     */
    private function sumLines(array $lines): array
    {
        $totals = ['subtotal' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
        foreach ($lines as $line) {
            $totals['subtotal'] += $line['subtotal'];
            foreach (['cgst', 'sgst', 'igst'] as $key) {
                $totals[$key] += $line['gst'][$key];
            }
        }
        $totals['tax'] = $totals['cgst'] + $totals['sgst'] + $totals['igst'];
        $totals['total'] = $totals['subtotal'] + $totals['tax'];

        return $totals;
    }

    /**
     * @param  array{subtotal: int, gst: array{cgst: int, sgst: int, igst: int}}  $line
     * @return array<string, string>
     */
    private function lineAmounts(array $line): array
    {
        $tax = array_sum($line['gst']);

        return [
            'line_subtotal' => Money::format($line['subtotal']),
            'line_tax' => Money::format($tax),
            'cgst_amount' => Money::format($line['gst']['cgst']),
            'sgst_amount' => Money::format($line['gst']['sgst']),
            'igst_amount' => Money::format($line['gst']['igst']),
            'line_total' => Money::format($line['subtotal'] + $tax),
        ];
    }
}
