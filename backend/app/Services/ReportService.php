<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The queries behind every report. The report screens page through them;
 * the PDF and Excel downloads read them in full, so both always agree.
 *
 * Filters: employee_id (int|null), search (string|null), type (string|null).
 *
 * @phpstan-type Filters array{employee_id?: int|string|null, search?: string|null, type?: string|null}
 */
class ReportService
{
    // ─── Orders ──────────────────────────────────────────────────────────

    /**
     * Bills in the period, optionally for one employee, newest first.
     *
     * @param  Filters  $filters
     * @return Builder<Order>
     */
    public function orders(ReportPeriod $period, array $filters = []): Builder
    {
        return $this->ordersIn($period)
            ->when($filters['employee_id'] ?? null, fn (Builder $q, $id) => $q->where('created_by', $id))
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $q) => $q
                ->where('order_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"))))
            ->latest()
            ->latest('id');
    }

    /**
     * @param  Builder<Order>  $orders
     * @return array{orders: int, subtotal: string, tax_total: string, grand_total: string, average_bill: string}
     */
    public function orderSummary(Builder $orders): array
    {
        $totals = $orders->clone()->reorder()
            ->selectRaw('COUNT(*) as orders, SUM(subtotal) as subtotal, SUM(tax_total) as tax, SUM(grand_total) as total')
            ->first();

        return [
            'orders' => (int) $totals->orders,
            'subtotal' => $this->money($totals->subtotal),
            'tax_total' => $this->money($totals->tax),
            'grand_total' => $this->money($totals->total),
            'average_bill' => $this->money($totals->orders ? $totals->total / $totals->orders : 0),
        ];
    }

    // ─── Customers ───────────────────────────────────────────────────────

    /**
     * Customers who bought in the period, biggest spenders first.
     *
     * @param  Filters  $filters
     * @return Builder<Customer>
     */
    public function customers(ReportPeriod $period, array $filters = []): Builder
    {
        $spend = $this->ordersIn($period)
            ->selectRaw('customer_id, COUNT(*) as orders_count, SUM(grand_total) as total_spent, MAX(created_at) as last_order_at')
            ->groupBy('customer_id');

        return Customer::query()
            ->joinSub($spend, 'spend', 'spend.customer_id', '=', 'customers.id')
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $q) => $q
                ->where('customers.name', 'like', "%{$term}%")
                ->orWhere('customers.email', 'like', "%{$term}%")
                ->orWhere('customers.phone', 'like', "%{$term}%")))
            ->select('customers.*', 'spend.orders_count', 'spend.total_spent', 'spend.last_order_at')
            ->orderByDesc('spend.total_spent')
            ->orderBy('customers.name');
    }

    /**
     * @param  Builder<Customer>  $customers
     * @return array{customers: int, new_customers: int, orders: int, total_spent: string}
     */
    public function customerSummary(Builder $customers, ReportPeriod $period): array
    {
        $totals = DB::query()
            ->fromSub($customers->clone()->reorder(), 'report')
            ->selectRaw('COUNT(*) as customers, SUM(orders_count) as orders, SUM(total_spent) as total')
            ->first();

        return [
            'customers' => (int) $totals->customers,
            'new_customers' => Customer::whereBetween('created_at', [$period->from, $period->to])->count(),
            'orders' => (int) $totals->orders,
            'total_spent' => $this->money($totals->total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerRow(Customer $customer, ReportPeriod $period): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'orders_count' => (int) $customer->orders_count,
            'total_spent' => $this->money($customer->total_spent),
            'average_bill' => $this->money($customer->total_spent / max(1, $customer->orders_count)),
            'last_order_at' => $this->iso($customer->last_order_at),
            'customer_since' => $customer->created_at?->toIso8601String(),
            'is_new' => $customer->created_at?->between($period->from, $period->to) ?? false,
        ];
    }

    // ─── Stock ───────────────────────────────────────────────────────────

    /**
     * Stock changes in the period, newest first. Type "adjustments" means
     * everything except sales.
     *
     * @param  Filters  $filters
     * @return Builder<StockMovement>
     */
    public function stock(ReportPeriod $period, array $filters = []): Builder
    {
        return StockMovement::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->when($filters['employee_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $type === 'adjustments'
                ? $q->where('type', '!=', StockMovement::TYPE_SALE)
                : $q->where('type', $type))
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->whereHas('product', fn (Builder $p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")))
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * @param  Builder<StockMovement>  $movements
     * @return array{entries: int, units_sold: int, units_restocked: int, corrections: int, correction_units: int}
     */
    public function stockSummary(Builder $movements): array
    {
        $byType = $movements->clone()->reorder()
            ->selectRaw('type, COUNT(*) as entries, SUM(quantity) as units')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $units = fn (string $type) => (int) ($byType[$type]->units ?? 0);

        return [
            'entries' => (int) $byType->sum('entries'),
            'units_sold' => -$units(StockMovement::TYPE_SALE),
            'units_restocked' => $units(StockMovement::TYPE_RESTOCK) + $units(StockMovement::TYPE_INITIAL),
            'corrections' => (int) ($byType[StockMovement::TYPE_CORRECTION]->entries ?? 0),
            'correction_units' => $units(StockMovement::TYPE_CORRECTION),
        ];
    }

    // ─── Employees ───────────────────────────────────────────────────────

    /**
     * What each employee did in the period: bills, sales and stock changes.
     *
     * @param  Filters  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function employees(ReportPeriod $period, array $filters = []): Collection
    {
        $sales = $this->ordersIn($period)
            ->whereNotNull('created_by')
            ->selectRaw('created_by, COUNT(*) as orders_count, SUM(grand_total) as sales_total, COUNT(DISTINCT customer_id) as customers_count, MAX(created_at) as last_bill_at')
            ->groupBy('created_by');

        $stock = StockMovement::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->where('type', '!=', StockMovement::TYPE_SALE)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as adjustments_count, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as units_added, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as units_removed')
            ->groupBy('user_id');

        return User::query()
            ->with('role')
            ->leftJoinSub($sales, 'sales', 'sales.created_by', '=', 'users.id')
            ->leftJoinSub($stock, 'stock', 'stock.user_id', '=', 'users.id')
            ->when($filters['employee_id'] ?? null, fn (Builder $q, $id) => $q->where('users.id', $id))
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $q) => $q
                ->where('users.name', 'like', "%{$term}%")
                ->orWhere('users.email', 'like', "%{$term}%")))
            ->select('users.*', 'sales.orders_count', 'sales.sales_total', 'sales.customers_count', 'sales.last_bill_at',
                'stock.adjustments_count', 'stock.units_added', 'stock.units_removed')
            ->orderByRaw('COALESCE(sales.sales_total, 0) DESC')
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'is_active' => $user->is_active,
                'orders_count' => (int) $user->orders_count,
                'sales_total' => $this->money($user->sales_total),
                'average_bill' => $this->money($user->orders_count ? $user->sales_total / $user->orders_count : 0),
                'customers_count' => (int) $user->customers_count,
                'last_bill_at' => $this->iso($user->last_bill_at),
                'adjustments_count' => (int) $user->adjustments_count,
                'units_added' => (int) $user->units_added,
                'units_removed' => (int) $user->units_removed,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $employees
     * @return array{employees: int, active_billers: int, orders: int, sales_total: string, adjustments: int}
     */
    public function employeeSummary(Collection $employees): array
    {
        return [
            'employees' => $employees->count(),
            'active_billers' => $employees->where('orders_count', '>', 0)->count(),
            'orders' => $employees->sum('orders_count'),
            'sales_total' => Money::format($employees->sum(fn ($e) => Money::toCents($e['sales_total']))),
            'adjustments' => $employees->sum('adjustments_count'),
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @return Builder<Order>
     */
    private function ordersIn(ReportPeriod $period): Builder
    {
        return Order::query()->whereBetween('orders.created_at', [$period->from, $period->to]);
    }

    private function money(string|int|float|null $amount): string
    {
        return Money::format(Money::toCents($amount ?? 0));
    }

    /**
     * Aggregated timestamps come back from the database as plain strings.
     */
    private function iso(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
