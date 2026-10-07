<?php

namespace App\Services;

use App\Models\Order;
use App\Support\AmountInWords;
use App\Support\Branding;
use App\Support\GstStates;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Everything printed on an A4 GST tax invoice for a bill, as plain data
 * shared by the JSON endpoint, the React print layout and the PDF.
 */
class TaxInvoiceService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['store', 'customer', 'items.product', 'cashier']);
        $store = $order->store;
        $customer = $order->customer;

        $lines = $order->items->values()->map(fn ($item, $index) => [
            'sr' => $index + 1,
            'product_id' => $item->product_id,
            'name' => $item->product?->name,
            'code' => $item->product?->code,
            'hsn_code' => $item->product?->hsn_code,
            'unit' => $item->product?->unit ?? 'pcs',
            'quantity' => $item->quantity,
            'rate' => $item->unit_price,
            'taxable_value' => $item->line_subtotal,
            'tax_percent' => $item->tax_percent,
            'cgst_percent' => $item->cgst_percent,
            'cgst_amount' => $item->cgst_amount,
            'sgst_percent' => $item->sgst_percent,
            'sgst_amount' => $item->sgst_amount,
            'igst_percent' => $item->igst_percent,
            'igst_amount' => $item->igst_amount,
            'total' => $item->line_total,
        ])->all();

        $placeOfSupply = $order->place_of_supply
            ?? ($order->is_interstate ? $customer?->state_code : $store?->state_code);

        $allExempt = $order->items->every(fn ($item) => (float) $item->tax_percent === 0.0);

        return [
            'id' => $order->id,
            'title' => $allExempt ? 'BILL OF SUPPLY' : 'TAX INVOICE',
            'copy' => 'Original for Recipient',
            'invoice_number' => $order->invoice_number,
            'order_number' => $order->order_number,
            'date' => $order->created_at?->toIso8601String(),
            'payment_mode' => $order->payment_mode,
            'is_interstate' => $order->is_interstate,
            'kind' => $order->customer_gstin ? 'b2b' : 'b2c',
            'cashier' => $order->cashier?->name ?? $order->created_by_name,
            // The seller: company legal details, with the store's own address / GSTIN when set.
            'store' => [...Branding::seller($store), 'code' => $store?->code],
            'customer' => [
                'id' => $customer?->id,
                'name' => $customer?->name,
                'email' => $customer?->email,
                'phone' => $customer?->phone,
                'gstin' => $order->customer_gstin,
                'billing_address' => $order->billing_address,
            ],
            'place_of_supply' => $placeOfSupply ? [
                'code' => $placeOfSupply,
                'state' => GstStates::name($placeOfSupply),
            ] : null,
            'lines' => $lines,
            'hsn_summary' => $this->hsnSummary($order),
            'totals' => [
                'quantity' => (int) $order->items->sum('quantity'),
                'taxable_value' => $order->subtotal,
                'cgst' => $order->cgst_amount,
                'sgst' => $order->sgst_amount,
                'igst' => $order->igst_amount,
                'tax' => $order->tax_total,
                'grand_total' => $order->grand_total,
                'amount_paid' => $order->amount_paid,
                'change_due' => $order->change_due,
            ],
            'amount_in_words' => AmountInWords::rupees($order->grand_total),
            'tax_in_words' => AmountInWords::rupees($order->tax_total),
        ];
    }

    /**
     * Tax per HSN code and rate, as GST returns and invoices require.
     *
     * @return list<array<string, mixed>>
     */
    public function hsnSummary(Order $order): array
    {
        return $order->items
            ->groupBy(fn ($item) => ($item->product?->hsn_code ?? '').'|'.(float) $item->tax_percent)
            ->map(function ($items) {
                $first = $items->first();
                $sum = fn (string $column) => Money::format($items->sum(fn ($item) => Money::toCents($item->{$column})));

                return [
                    'hsn_code' => $first->product?->hsn_code,
                    'tax_percent' => $first->tax_percent,
                    'quantity' => (int) $items->sum('quantity'),
                    'taxable_value' => $sum('line_subtotal'),
                    'cgst_amount' => $sum('cgst_amount'),
                    'sgst_amount' => $sum('sgst_amount'),
                    'igst_amount' => $sum('igst_amount'),
                    'tax' => $sum('line_tax'),
                ];
            })
            ->sortBy([['hsn_code', 'asc'], ['tax_percent', 'asc']])
            ->values()
            ->all();
    }

    public function pdf(Order $order): string
    {
        return Pdf::loadView('documents.tax-invoice', ['invoice' => $this->build($order)])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    public function filename(Order $order): string
    {
        return 'Invoice-'.str_replace('/', '-', $order->invoice_number ?? $order->order_number).'.pdf';
    }
}
