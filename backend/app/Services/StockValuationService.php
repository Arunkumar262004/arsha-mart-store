<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Value of the goods on hand at the end of a day, for the P&L (opening and
 * closing stock) and the balance sheet.
 *
 * Units at a date = today's stock minus every movement logged after that day,
 * per product and store (never below zero). Each unit is valued at the
 * product's cost price, or at its selling price excluding GST when no cost
 * price is recorded.
 */
class StockValuationService
{
    /**
     * @return array{value: int, units: int, products: int, at_selling_price: int, valuation_basis: string}
     */
    public function valueAt(?int $storeId, CarbonInterface $date): array
    {
        $endOfDay = Carbon::parse($date)->endOfDay();

        $current = ProductStock::query()
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->get(['product_id', 'store_id', 'stock']);

        $later = $endOfDay->isFuture() ? collect() : StockMovement::query()
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->where('created_at', '>', $endOfDay)
            ->groupBy('product_id', 'store_id')
            ->selectRaw('product_id, store_id, COALESCE(SUM(quantity), 0) as quantity')
            ->get()
            ->keyBy(fn ($row) => $row->product_id.':'.$row->store_id);

        $units = [];
        foreach ($current as $row) {
            $moved = (int) ($later->get($row->product_id.':'.$row->store_id)?->quantity ?? 0);
            $units[$row->product_id] = ($units[$row->product_id] ?? 0) + max(0, (int) $row->stock - $moved);
        }
        $units = array_filter($units);

        $products = Product::query()->whereKey(array_keys($units))->get(['id', 'price', 'cost_price']);

        $value = 0;
        $atSellingPrice = 0;
        foreach ($products as $product) {
            if ($product->cost_price === null) {
                $atSellingPrice++;
            }
            $value += $units[$product->id] * Money::toCents($product->cost_price ?? $product->price);
        }

        return [
            'value' => $value,
            'units' => array_sum($units),
            'products' => count($units),
            'at_selling_price' => $atSellingPrice,
            'valuation_basis' => $atSellingPrice === 0
                ? 'Cost price'
                : "Cost price; selling price excluding GST for {$atSellingPrice} product(s) without a cost price",
        ];
    }
}
