<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $threshold = (int) config('inventory.low_stock_threshold');
        $today = now()->startOfDay();

        return response()->json([
            'billing' => [
                'today_sales' => $this->salesSince($today),
                'today_orders' => Order::where('created_at', '>=', $today)->count(),
                'month_sales' => $this->salesSince(now()->startOfMonth()),
                'month_orders' => Order::where('created_at', '>=', now()->startOfMonth())->count(),
                'last_7_days' => $this->lastSevenDays(),
                'top_products' => $this->topProducts(now()->startOfMonth()),
                'recent_orders' => Order::with('customer')->latest()->latest('id')->limit(5)->get()
                    ->map(fn (Order $order) => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer' => $order->customer->name,
                        'email' => $order->customer->email,
                        'grand_total' => $order->grand_total,
                        'created_at' => $order->created_at->toIso8601String(),
                    ]),
            ],
            'stock' => [
                'threshold' => $threshold,
                'products' => Product::count(),
                'units' => (int) Product::sum('stock'),
                'low_stock' => Product::belowStock($threshold)->where('stock', '>', 0)->count(),
                'out_of_stock' => Product::where('stock', 0)->count(),
                'lowest' => ProductResource::collection(
                    Product::belowStock($threshold)->orderBy('stock')->orderBy('name')->limit(6)->get()
                ),
            ],
        ]);
    }

    private function salesSince(Carbon $from): string
    {
        return Money::format(Money::toCents(Order::where('created_at', '>=', $from)->sum('grand_total')));
    }

    /**
     * This month's sales split by product: the top 5 by revenue, the rest
     * summed as "Other". Uses the prices saved on each bill line.
     *
     * @return list<array{product_id: int|null, name: string, total: string, units: int}>
     */
    private function topProducts(Carbon $from, int $limit = 5): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.created_at', '>=', $from)
            ->groupBy('order_items.product_id', 'products.name')
            ->selectRaw('order_items.product_id, products.name, SUM(order_items.line_total) as total, SUM(order_items.quantity) as units')
            ->orderByDesc('total')
            ->get();

        $slice = fn ($id, ?string $name, $total, $units) => [
            'product_id' => $id,
            'name' => $name ?? 'Deleted product',
            'total' => Money::format(Money::toCents($total)),
            'units' => (int) $units,
        ];

        $top = $rows->take($limit)->map(fn ($r) => $slice($r->product_id, $r->name, $r->total, $r->units));
        $rest = $rows->slice($limit);

        if ($rest->isNotEmpty()) {
            $top->push($slice(null, 'Other ('.$rest->count().' products)', $rest->sum('total'), $rest->sum('units')));
        }

        return $top->values()->all();
    }

    /**
     * Daily totals for the last 7 days, including days with no sales.
     *
     * @return list<array{date: string, total: string, orders: int}>
     */
    private function lastSevenDays(): array
    {
        $from = now()->subDays(6)->startOfDay();

        $rows = Order::where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, SUM(grand_total) as total, COUNT(*) as orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        return collect(range(0, 6))->map(function (int $offset) use ($from, $rows) {
            $day = $from->copy()->addDays($offset)->toDateString();

            return [
                'date' => $day,
                'total' => Money::format(Money::toCents($rows[$day]->total ?? 0)),
                'orders' => (int) ($rows[$day]->orders ?? 0),
            ];
        })->all();
    }
}
