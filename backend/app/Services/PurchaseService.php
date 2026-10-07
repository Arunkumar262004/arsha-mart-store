<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Concerns\PostsDocuments;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods received from suppliers: stock in, latest cost price, and the
 * purchase (and payment) vouchers, all in one transaction.
 */
class PurchaseService
{
    use PostsDocuments;

    public function __construct(
        private readonly StockService $stock,
        private readonly AccountingService $accounting,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Record a purchase at $store.
     *
     * GST: CGST + SGST when the supplier is in the store's state, IGST when
     * not (decided from the two state codes unless is_interstate is given).
     * The grand total is rounded to the nearest rupee; the difference goes
     * to the Round Off account.
     *
     * Vouchers:
     *   Purchase: Dr Purchases (taxable), Dr Freight Inward, Dr Input GST,
     *             Dr/Cr Round Off, Cr Supplier (grand total)
     *   Payment (cash / bank purchases): Dr Supplier, Cr Cash / Bank (amount paid)
     *
     * @param  array{supplier_id: int, supplier_invoice_number?: ?string, supplier_invoice_date?: ?string, date?: ?string,
     *     is_interstate?: ?bool, payment_mode: string, amount_paid?: string|float|null, freight?: string|float|null,
     *     notes?: ?string, items: list<array{product_id: int, quantity: int, unit_cost: string|float, tax_percent?: string|float|null}>}  $data
     */
    public function create(array $data, Store $store, ?User $user): Purchase
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $date = Carbon::parse($data['date'] ?? now())->startOfDay();
        $interstate = $data['is_interstate'] ?? self::isInterstate($supplier, $store);
        $items = array_values($data['items']);

        $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');

        $lines = array_map(function (array $item) use ($products, $interstate) {
            $product = $products->get((int) $item['product_id']);
            $quantity = (int) $item['quantity'];
            $rate = (float) ($item['tax_percent'] ?? $product->tax_percent);
            $subtotal = Money::toCents($item['unit_cost']) * $quantity;
            $gst = $this->gstSplit($subtotal, $rate, $interstate);

            return compact('product', 'quantity', 'rate', 'subtotal', 'gst') + ['unit_cost' => $item['unit_cost']];
        }, $items);

        $subtotal = array_sum(array_column($lines, 'subtotal'));
        $taxes = ['cgst' => 0, 'sgst' => 0, 'igst' => 0];
        foreach ($lines as $line) {
            foreach ($taxes as $key => $sum) {
                $taxes[$key] = $sum + $line['gst'][$key];
            }
        }
        $tax = array_sum($taxes);
        $freight = Money::toCents($data['freight'] ?? 0);
        $beforeRounding = $subtotal + $tax + $freight;
        $grandTotal = intdiv($beforeRounding + 50, 100) * 100;
        $roundOff = $grandTotal - $beforeRounding;

        $mode = $data['payment_mode'];
        $paid = 0;
        if ($mode !== Purchase::MODE_CREDIT) {
            $given = $data['amount_paid'] ?? null;
            $paid = ($given === null || $given === '') ? $grandTotal : Money::toCents($given);
            if ($paid <= 0 || $paid > $grandTotal) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Amount paid must be more than 0 and at most the grand total ('.Money::format($grandTotal).').',
                ]);
            }
        }

        return DB::transaction(function () use ($data, $store, $user, $supplier, $date, $interstate, $lines, $subtotal, $taxes, $tax, $freight, $roundOff, $grandTotal, $mode, $paid) {
            $purchase = Purchase::create([
                'store_id' => $store->id,
                'number' => $this->numbers->next('purchase', $store, $date),
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
                'date' => $date->toDateString(),
                'is_interstate' => $interstate,
                'payment_mode' => $mode,
                'subtotal' => Money::format($subtotal),
                'cgst_amount' => Money::format($taxes['cgst']),
                'sgst_amount' => Money::format($taxes['sgst']),
                'igst_amount' => Money::format($taxes['igst']),
                'tax_total' => Money::format($tax),
                'freight' => Money::format($freight),
                'round_off' => Money::format($roundOff),
                'grand_total' => Money::format($grandTotal),
                'amount_paid' => Money::format($paid),
                'status' => Purchase::STATUS_POSTED,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name,
            ]);

            $purchase->items()->createMany(array_map(fn (array $line) => [
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_cost' => Money::format(Money::toCents($line['unit_cost'])),
                'tax_percent' => $line['rate'],
                'line_subtotal' => Money::format($line['subtotal']),
                'line_tax' => Money::format(array_sum($line['gst'])),
                'cgst_amount' => Money::format($line['gst']['cgst']),
                'sgst_amount' => Money::format($line['gst']['sgst']),
                'igst_amount' => Money::format($line['gst']['igst']),
                'line_total' => Money::format($line['subtotal'] + array_sum($line['gst'])),
            ], $lines));

            $this->stock->moveMany(
                $store,
                array_map(fn (array $line) => ['product_id' => $line['product']->id, 'quantity' => $line['quantity']], $lines),
                StockMovement::TYPE_PURCHASE,
                $user,
                $purchase->number,
                $purchase,
            );

            // The latest purchase sets the cost price (used for margins and stock value).
            foreach ($lines as $line) {
                Product::query()->whereKey($line['product']->id)->update(['cost_price' => Money::format(Money::toCents($line['unit_cost']))]);
            }

            $ledger = Account::forParty($supplier);
            $narration = "Purchase {$purchase->number} from {$supplier->name}"
                .($purchase->supplier_invoice_number ? " (bill {$purchase->supplier_invoice_number})" : '');

            $this->accounting->post(Voucher::PURCHASE, [
                [Account::PURCHASES, Money::format($subtotal), 0],
                [Account::FREIGHT_INWARD, Money::format($freight), 0],
                [Account::INPUT_CGST, Money::format($taxes['cgst']), 0],
                [Account::INPUT_SGST, Money::format($taxes['sgst']), 0],
                [Account::INPUT_IGST, Money::format($taxes['igst']), 0],
                [Account::ROUND_OFF, Money::format(max($roundOff, 0)), Money::format(max(-$roundOff, 0))],
                [$ledger, 0, Money::format($grandTotal)],
            ], $store, $date, mb_substr($narration, 0, 255), $purchase, $user, $purchase->number);

            if ($paid > 0) {
                $this->accounting->post(Voucher::PAYMENT, [
                    [$ledger, Money::format($paid), 0],
                    [$mode === Purchase::MODE_BANK ? Account::BANK : Account::CASH, 0, Money::format($paid)],
                ], $store, $date, mb_substr("Paid {$supplier->name} for purchase {$purchase->number} ({$mode})", 0, 255), $purchase, $user);
            }

            return $purchase;
        });
    }

    /**
     * Undo a purchase: take the goods back out of the store and post a
     * journal reversing its vouchers. Not allowed once anything was returned
     * against it, or when the store no longer has the stock (already sold).
     */
    public function cancel(Purchase $purchase, ?User $user): Purchase
    {
        if ($purchase->isCancelled()) {
            throw ValidationException::withMessages(['purchase' => 'This purchase is already cancelled.']);
        }

        if ($purchase->returns()->exists()) {
            throw ValidationException::withMessages(['purchase' => 'Goods were returned against this purchase; it cannot be cancelled.']);
        }

        return DB::transaction(function () use ($purchase, $user) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $purchase->load(['items', 'store']);

            $this->stock->moveMany(
                $purchase->store,
                $purchase->items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => -$item->quantity])->all(),
                StockMovement::TYPE_PURCHASE_RETURN,
                $user,
                "Cancelled {$purchase->number}",
                $purchase,
            );

            $this->reverseVouchers(
                $purchase->vouchers()->get(),
                $purchase->store,
                "Cancelled purchase {$purchase->number}",
                $purchase,
                $user,
            );

            $purchase->update(['status' => Purchase::STATUS_CANCELLED, 'cancelled_at' => now()]);

            return $purchase;
        });
    }

    /**
     * Inter-state when both state codes are known and differ.
     */
    public static function isInterstate(Supplier $supplier, Store $store): bool
    {
        return filled($supplier->state_code) && filled($store->state_code) && $supplier->state_code !== $store->state_code;
    }
}
