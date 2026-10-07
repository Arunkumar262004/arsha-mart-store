<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Support\Money;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Where the sales come from: categories and their margin, payment modes,
 * stores, busy days and hours, and the best products.
 *
 * Cost is units sold × the product's CURRENT cost price, so the margin is an
 * approximation; lines of products without a cost price are left out of the
 * margin (their sales are still counted).
 */
class SalesAnalysisService
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * @return array<string, mixed>
     */
    public function analyse(?int $storeId, ReportPeriod $period): array
    {
        $lines = fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->when($storeId, fn ($q, int $id) => $q->where('orders.store_id', $id))
            ->whereBetween('orders.created_at', [$period->from, $period->to]);

        $measures = 'COUNT(DISTINCT orders.id) as bills, COALESCE(SUM(order_items.quantity), 0) as units,
            COALESCE(SUM(order_items.line_subtotal), 0) as sales,
            COALESCE(SUM(CASE WHEN products.cost_price IS NULL THEN 0 ELSE order_items.line_subtotal END), 0) as costed_sales,
            COALESCE(SUM(CASE WHEN products.cost_price IS NULL THEN 0 ELSE order_items.quantity * products.cost_price END), 0) as cost,
            COALESCE(SUM(CASE WHEN products.cost_price IS NULL THEN order_items.quantity ELSE 0 END), 0) as uncosted_units';

        $categories = $lines()
            ->groupBy('products.category')
            ->selectRaw('products.category as category, '.$measures)
            ->get()
            ->map(fn ($r) => ['category' => $r->category ?: 'Uncategorised', ...$this->measures($r)])
            ->sortByDesc(fn ($r) => Money::toCents($r['sales']))
            ->values();

        $products = $lines()
            ->groupBy('products.id', 'products.name', 'products.code', 'products.category')
            ->selectRaw('products.id as id, products.name as name, products.code as code, products.category as category, '.$measures)
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'code' => $r->code, 'category' => $r->category ?: 'Uncategorised', ...$this->measures($r)]);

        $orders = fn () => Order::query()
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->whereBetween('created_at', [$period->from, $period->to]);

        $modes = $orders()
            ->groupBy('payment_mode')
            ->selectRaw('payment_mode, COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total')
            ->get()
            ->map(fn ($r) => ['mode' => $r->payment_mode, 'bills' => (int) $r->bills, 'total' => Money::format(Money::toCents($r->total))])
            ->sortByDesc(fn ($r) => Money::toCents($r['total']))
            ->values();

        $storeNames = Store::query()->pluck('name', 'id');
        $stores = $orders()
            ->groupBy('store_id')
            ->selectRaw('store_id, COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total, COALESCE(SUM(subtotal), 0) as taxable')
            ->get()
            ->map(fn ($r) => [
                'store_id' => $r->store_id,
                'store' => $storeNames[$r->store_id] ?? 'Unknown',
                'bills' => (int) $r->bills,
                'total' => Money::format(Money::toCents($r->total)),
                'taxable' => Money::format(Money::toCents($r->taxable)),
                'average_bill' => Money::format($r->bills > 0 ? intdiv(Money::toCents($r->total), (int) $r->bills) : 0),
            ])
            ->sortByDesc(fn ($r) => Money::toCents($r['total']))
            ->values();

        // Weekday and hour in PHP (in the app timezone) to stay database-neutral.
        $weekdays = array_fill_keys(self::DAYS, ['bills' => 0, 'total' => 0]);
        $hours = array_fill(0, 24, ['bills' => 0, 'total' => 0]);
        $bills = 0;
        $revenue = 0;
        foreach ($orders()->select(['id', 'created_at', 'grand_total'])->lazyById(1000) as $order) {
            $at = $order->created_at->copy()->setTimezone(config('app.timezone'));
            $cents = Money::toCents($order->grand_total);
            $day = self::DAYS[$at->dayOfWeekIso - 1];
            $weekdays[$day]['bills']++;
            $weekdays[$day]['total'] += $cents;
            $hours[$at->hour]['bills']++;
            $hours[$at->hour]['total'] += $cents;
            $bills++;
            $revenue += $cents;
        }

        $sales = $categories->sum(fn ($r) => Money::toCents($r['sales']));
        $costedSales = $categories->sum(fn ($r) => Money::toCents($r['costed_sales']));
        $cost = $categories->sum(fn ($r) => Money::toCents($r['cost']));

        return [
            'period' => $period->toArray(),
            'summary' => [
                'bills' => $bills,
                'revenue' => Money::format($revenue),
                'taxable_sales' => Money::format($sales),
                'units' => $categories->sum('units'),
                'cost' => Money::format($cost),
                'gross_margin' => Money::format($costedSales - $cost),
                'margin_percent' => $this->percent($costedSales - $cost, $costedSales),
                'average_bill' => Money::format($bills > 0 ? intdiv($revenue, $bills) : 0),
                'uncosted_units' => $categories->sum('uncosted_units'),
            ],
            'categories' => $categories->all(),
            'payment_modes' => $modes->all(),
            'stores' => $stores->all(),
            'weekdays' => collect($weekdays)->map(fn ($v, $day) => ['day' => $day, 'bills' => $v['bills'], 'total' => Money::format($v['total'])])->values()->all(),
            'hours' => collect($hours)->map(fn ($v, $hour) => ['hour' => $hour, 'bills' => $v['bills'], 'total' => Money::format($v['total'])])->values()->all(),
            'top_by_revenue' => $products->sortByDesc(fn ($r) => Money::toCents($r['sales']))->take(10)->values()->all(),
            'top_by_margin' => $products->filter(fn ($r) => Money::toCents($r['costed_sales']) > 0)
                ->sortByDesc(fn ($r) => Money::toCents($r['margin']))->take(10)->values()->all(),
            'cost_note' => 'Cost = units sold × current cost price (an approximation). Products without a cost price are left out of the margin.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function measures(object $row): array
    {
        $costedSales = Money::toCents($row->costed_sales);
        $cost = Money::toCents($row->cost);

        return [
            'bills' => (int) $row->bills,
            'units' => (int) $row->units,
            'sales' => Money::format(Money::toCents($row->sales)),
            'costed_sales' => Money::format($costedSales),
            'cost' => Money::format($cost),
            'margin' => Money::format($costedSales - $cost),
            'margin_percent' => $this->percent($costedSales - $cost, $costedSales),
            'uncosted_units' => (int) $row->uncosted_units,
        ];
    }

    private function percent(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part * 100 / $whole, 1);
    }
}
