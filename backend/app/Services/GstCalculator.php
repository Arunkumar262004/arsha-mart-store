<?php

namespace App\Services;

use App\Support\Money;

/**
 * GST on a document line, in paise (integers) to avoid rounding errors.
 * Used by bills, quotations and anything else that prices lines.
 *
 * Within the state, CGST and SGST are each worked out at half the rate;
 * across states (interstate supply), IGST is the full rate.
 */
class GstCalculator
{
    /**
     * @return array{subtotal_cents: int, tax_cents: int, total_cents: int, gst: array{cgst_percent: float|int, cgst_cents: int, sgst_percent: float|int, sgst_cents: int, igst_percent: float|int, igst_cents: int}}
     */
    public static function line(int $unitPriceCents, int $quantity, string|int|float $rate, bool $interstate): array
    {
        $subtotal = $unitPriceCents * $quantity;
        $rate = (float) $rate;

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

        $tax = $gst['cgst_cents'] + $gst['sgst_cents'] + $gst['igst_cents'];

        return [
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $subtotal + $tax,
            'gst' => $gst,
        ];
    }

    /**
     * Document totals from lines produced by line(), as decimal strings ready
     * to save: subtotal, tax_total, cgst/sgst/igst_amount, grand_total.
     *
     * @param  array<int, array{subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}>  $lines
     * @return array<string, string>
     */
    public static function totals(array $lines): array
    {
        $subtotal = array_sum(array_column($lines, 'subtotal_cents'));
        $tax = array_sum(array_column($lines, 'tax_cents'));
        $sumOf = fn (string $key) => Money::format(array_sum(array_map(fn (array $line) => $line['gst'][$key], $lines)));

        return [
            'subtotal' => Money::format($subtotal),
            'tax_total' => Money::format($tax),
            'cgst_amount' => $sumOf('cgst_cents'),
            'sgst_amount' => $sumOf('sgst_cents'),
            'igst_amount' => $sumOf('igst_cents'),
            'grand_total' => Money::format($subtotal + $tax),
        ];
    }

    /**
     * The per-line columns shared by order_items and quotation_items.
     *
     * @param  array{subtotal_cents: int, tax_cents: int, gst: array<string, float|int>}  $line
     * @return array<string, mixed>
     */
    public static function lineColumns(array $line): array
    {
        return [
            'line_subtotal' => Money::format($line['subtotal_cents']),
            'line_tax' => Money::format($line['tax_cents']),
            'cgst_percent' => $line['gst']['cgst_percent'],
            'cgst_amount' => Money::format($line['gst']['cgst_cents']),
            'sgst_percent' => $line['gst']['sgst_percent'],
            'sgst_amount' => Money::format($line['gst']['sgst_cents']),
            'igst_percent' => $line['gst']['igst_percent'],
            'igst_amount' => Money::format($line['gst']['igst_cents']),
            'line_total' => Money::format($line['subtotal_cents'] + $line['tax_cents']),
        ];
    }
}
