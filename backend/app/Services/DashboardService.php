<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Support\Money;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The figures behind the dashboard, for the store being viewed (every store
 * in "all stores" mode). Month-to-date figures are compared with the same
 * days of last month, so the 8th is compared with last month's 1st-8th.
 */
class DashboardService
{
    public function __construct(
        private readonly StoreContext $context,
        private readonly OutstandingService $outstanding,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(?int $year = null): array
    {
        $monthStart = now()->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $lastMonthToDate = $lastMonthStart->copy()->addDays(now()->day - 1)->endOfDay()->min($monthStart->copy()->subSecond());

        return [
            'kpis' => [
                ...$this->kpis($monthStart, $lastMonthStart, $lastMonthToDate),
                ...$this->money($monthStart, $lastMonthStart, $lastMonthToDate),
            ],
            'monthly' => $this->monthly($year ?? now()->year),
            'best_sellers' => $this->bestSellers($monthStart, $lastMonthStart, $lastMonthToDate),
            'payment_modes' => $this->paymentModes($monthStart),
            'categories' => $this->categories($monthStart),
            'inventory' => $this->inventory(),
            'store' => $this->storeSummary(),
            'recent' => $this->recent(),
        ];
    }

    /**
     * Sales, bills, customers and gross profit this month, the change against
     * the same days last month, and a daily series for the last 30 days.
     *
     * @return array<string, array{value: string|int, previous: string|int, change: float|null, series: list<float>}>
     */
    private function kpis(Carbon $from, Carbon $prevFrom, Carbon $prevTo): array
    {
        $now = now();
        $current = $this->totals($from, $now);
        $previous = $this->totals($prevFrom, $prevTo);

        $days = collect(range(29, 0))->map(fn (int $ago) => $now->copy()->subDays($ago)->toDateString());
        $daily = $this->orders()
            ->where('orders.created_at', '>=', $now->copy()->subDays(29)->startOfDay())
            ->selectRaw('DATE(orders.created_at) as day, SUM(grand_total) as sales, COUNT(*) as bills, COUNT(DISTINCT customer_id) as customers')
            ->groupBy('day')
            ->get()
            ->keyBy('day');
        $profit = $this->items()
            ->where('orders.created_at', '>=', $now->copy()->subDays(29)->startOfDay())
            ->selectRaw('DATE(orders.created_at) as day, SUM(order_items.line_subtotal) as sales, SUM(CASE WHEN products.cost_price IS NULL THEN order_items.line_subtotal ELSE order_items.quantity * products.cost_price END) as cost')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = fn (callable $value) => $days->map(fn (string $day) => round((float) $value($day), 2))->values()->all();

        return [
            'sales' => [
                'value' => Money::format($current['sales']),
                'previous' => Money::format($previous['sales']),
                'change' => $this->change($current['sales'], $previous['sales']),
                'series' => $series(fn ($d) => $daily[$d]->sales ?? 0),
            ],
            'orders' => [
                'value' => $current['bills'],
                'previous' => $previous['bills'],
                'change' => $this->change($current['bills'], $previous['bills']),
                'series' => $series(fn ($d) => $daily[$d]->bills ?? 0),
            ],
            'customers' => [
                'value' => $current['customers'],
                'previous' => $previous['customers'],
                'change' => $this->change($current['customers'], $previous['customers']),
                'series' => $series(fn ($d) => $daily[$d]->customers ?? 0),
            ],
            'profit' => [
                'value' => Money::format($current['profit']),
                'previous' => Money::format($previous['profit']),
                'change' => $this->change($current['profit'], $previous['profit']),
                'series' => $series(fn ($d) => isset($profit[$d]) ? $profit[$d]->sales - $profit[$d]->cost : 0),
            ],
        ];
    }

    /**
     * @return array{sales: int, bills: int, customers: int, profit: int} money in paise
     */
    private function totals(Carbon $from, Carbon $to): array
    {
        $orders = $this->orders()->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(grand_total), 0) as sales, COUNT(*) as bills, COUNT(DISTINCT customer_id) as customers')
            ->first();

        // Gross profit: taxable value minus cost (current cost price; lines
        // without a cost price count as no profit rather than all profit).
        $margin = $this->items()->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(order_items.line_subtotal), 0) as sales, COALESCE(SUM(CASE WHEN products.cost_price IS NULL THEN order_items.line_subtotal ELSE order_items.quantity * products.cost_price END), 0) as cost')
            ->first();

        return [
            'sales' => Money::toCents($orders->sales),
            'bills' => (int) $orders->bills,
            'customers' => (int) $orders->customers,
            'profit' => Money::toCents($margin->sales) - Money::toCents($margin->cost),
        ];
    }

    /**
     * Sales per month of a year, plus the years that have bills (for the picker).
     *
     * @return array{year: int, years: list<int>, total: string, months: list<array{month: int, total: string, orders: int}>}
     */
    private function monthly(int $year): array
    {
        $months = [];
        $sum = 0;

        foreach (range(1, 12) as $month) {
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            $row = $this->orders()->whereBetween('orders.created_at', [$start, $start->copy()->endOfMonth()])
                ->selectRaw('COALESCE(SUM(grand_total), 0) as total, COUNT(*) as orders')
                ->first();
            $cents = Money::toCents($row->total);
            $sum += $cents;
            $months[] = ['month' => $month, 'total' => Money::format($cents), 'orders' => (int) $row->orders];
        }

        $first = $this->orders()->min('orders.created_at');
        $firstYear = $first ? Carbon::parse($first)->year : now()->year;

        return [
            'year' => $year,
            'years' => array_reverse(range(min($firstYear, $year), max(now()->year, $year))),
            'total' => Money::format($sum),
            'months' => $months,
        ];
    }

    /**
     * Top 5 products by revenue this month, with the change against the same
     * days last month.
     *
     * @return list<array<string, mixed>>
     */
    private function bestSellers(Carbon $from, Carbon $prevFrom, Carbon $prevTo): array
    {
        $rows = $this->items()->where('orders.created_at', '>=', $from)
            ->groupBy('order_items.product_id', 'products.name', 'products.category', 'products.unit')
            ->selectRaw('order_items.product_id, products.name, products.category, products.unit, SUM(order_items.quantity) as units, SUM(order_items.line_total) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $before = $this->items()->whereBetween('orders.created_at', [$prevFrom, $prevTo])
            ->whereIn('order_items.product_id', $rows->pluck('product_id'))
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.line_total) as total')
            ->pluck('total', 'order_items.product_id');

        return $rows->map(fn ($r) => [
            'product_id' => $r->product_id,
            'name' => $r->name ?? 'Deleted product',
            'category' => $r->category,
            'unit' => $r->unit ?? 'pcs',
            'units' => (int) $r->units,
            'total' => Money::format(Money::toCents($r->total)),
            'change' => $this->change(Money::toCents($r->total), Money::toCents($before[$r->product_id] ?? 0)),
        ])->values()->all();
    }

    /**
     * @return list<array{mode: string, orders: int, total: string}>
     */
    private function paymentModes(Carbon $from): array
    {
        $rows = $this->orders()->where('orders.created_at', '>=', $from)
            ->groupBy('payment_mode')
            ->selectRaw('payment_mode, COUNT(*) as orders, SUM(grand_total) as total')
            ->get()
            ->keyBy('payment_mode');

        return array_map(fn (string $mode) => [
            'mode' => $mode,
            'orders' => (int) ($rows[$mode]->orders ?? 0),
            'total' => Money::format(Money::toCents($rows[$mode]->total ?? 0)),
        ], Order::PAYMENT_MODES);
    }

    /**
     * This month's sales by product category: the top 4, the rest as "Others".
     *
     * @return list<array{name: string, total: string}>
     */
    private function categories(Carbon $from): array
    {
        $rows = $this->items()->where('orders.created_at', '>=', $from)
            ->groupBy('products.category')
            ->selectRaw('products.category, SUM(order_items.line_total) as total')
            ->orderByDesc('total')
            ->get();

        $slice = fn (string $name, $total) => ['name' => $name, 'total' => Money::format(Money::toCents($total))];
        $top = $rows->take(4)->map(fn ($r) => $slice($r->category ?: 'Uncategorised', $r->total));

        if ($rows->count() > 4) {
            $top->push($slice('Others', $rows->slice(4)->sum('total')));
        }

        return $top->values()->all();
    }

    /**
     * @return array{products: int, low_stock: int, out_of_stock: int, value: string, threshold: int}
     */
    private function inventory(): array
    {
        $threshold = (int) config('inventory.low_stock_threshold');
        $out = Product::stockEquals(0)->count();

        // Stock value at cost (selling price when no cost price is set).
        $value = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->when($this->context->scopeId(), fn ($q, int $id) => $q->where('product_stocks.store_id', $id))
            ->selectRaw('COALESCE(SUM(product_stocks.stock * COALESCE(products.cost_price, products.price)), 0) as value')
            ->value('value');

        return [
            'products' => Product::count(),
            'low_stock' => Product::belowStock($threshold)->count() - $out,
            'out_of_stock' => $out,
            'value' => Money::format(Money::toCents($value)),
            'threshold' => $threshold,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function storeSummary(): array
    {
        $today = now()->startOfDay();
        $storeId = $this->context->scopeId();

        return [
            'branches' => Store::active()->count(),
            // Active staff who can work here: assigned to this store, or to every store.
            'employees' => User::where('is_active', true)
                ->when($storeId, fn ($q, int $id) => $q->where(fn ($q) => $q->where('store_id', $id)->orWhereNull('store_id')))
                ->count(),
            'today_sales' => Money::format(Money::toCents($this->orders()->where('orders.created_at', '>=', $today)->sum('grand_total'))),
            'today_orders' => $this->orders()->where('orders.created_at', '>=', $today)->count(),
            'new_customers' => Customer::where('created_at', '>=', $today)->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(): array
    {
        return $this->orders()->with('customer')->withCount('items')->latest()->latest('id')->limit(6)->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'invoice_number' => $order->invoice_number ?? $order->order_number,
                'customer' => $order->customer->name,
                'email' => $order->customer->email,
                'created_at' => $order->created_at->toIso8601String(),
                'items' => $order->items_count,
                'grand_total' => $order->grand_total,
                'payment_mode' => $order->payment_mode,
                // Credit bills are owed until a receipt settles the customer's ledger.
                'status' => $order->payment_mode === Order::PAYMENT_CREDIT ? 'due' : 'paid',
            ])->all();
    }

    /**
     * Collected this month (money in from bills and customer receipts) and
     * what customers owe now, each against the same point last month.
     *
     * @return array<string, array{value: string, previous: string, change: float|null}>
     */
    private function money(Carbon $from, Carbon $prevFrom, Carbon $prevTo): array
    {
        $collected = $this->collections($from, now());
        $collectedBefore = $this->collections($prevFrom, $prevTo);
        $owed = Money::toCents($this->outstanding->report($this->context->scopeId(), 'receivable', now())['totals']['balance']);
        $owedBefore = Money::toCents($this->outstanding->report($this->context->scopeId(), 'receivable', $prevTo)['totals']['balance']);

        return [
            'collected' => [
                'value' => Money::format($collected),
                'previous' => Money::format($collectedBefore),
                'change' => $this->change($collected, $collectedBefore),
            ],
            'outstanding' => [
                'value' => Money::format($owed),
                'previous' => Money::format($owedBefore),
                'change' => $this->change($owed, $owedBefore),
            ],
        ];
    }

    private function collections(Carbon $from, Carbon $to): int
    {
        return Money::toCents($this->collectionEntries($from, $to)->sum('voucher_entries.debit'));
    }

    /**
     * Cash / bank debits from sales and receipt vouchers: the money that came in.
     *
     * @return Builder<VoucherEntry>
     */
    private function collectionEntries(Carbon $from, Carbon $to): Builder
    {
        return VoucherEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_entries.account_id')
            ->whereIn('accounts.group', ['cash', 'bank'])
            ->whereIn('vouchers.type', [Voucher::SALES, Voucher::RECEIPT])
            ->where('voucher_entries.date', '>=', $from->toDateString())
            ->where('voucher_entries.date', '<', $to->copy()->addDay()->toDateString())
            ->when($this->context->scopeId(), fn (Builder $q, int $id) => $q->where('voucher_entries.store_id', $id));
    }

    /**
     * Percentage change, or null when there is nothing to compare with.
     */
    private function change(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }

    /**
     * @return Builder<Order>
     */
    private function orders(): Builder
    {
        return Order::query()->when($this->context->scopeId(), fn (Builder $q, int $id) => $q->where('orders.store_id', $id));
    }

    /**
     * Bill lines joined to their bill and product, in the store scope.
     *
     * @return Builder<OrderItem>
     */
    private function items(): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->when($this->context->scopeId(), fn (Builder $q, int $id) => $q->where('orders.store_id', $id));
    }
}
