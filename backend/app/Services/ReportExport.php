<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ReportPeriod;
use Illuminate\Support\Carbon;

/**
 * Describes a report for download: title, filters, summary and one or
 * more sheets of rows. ReportPdf and ReportSpreadsheet turn it into a file.
 *
 * A sheet marked `only` goes into that format alone. A `grouped` sheet has
 * `groups` (a header row plus its item rows) instead of plain `rows`.
 *
 * Column types: text, int, money, datetime. Money cells hold floats and
 * datetime cells hold Carbon instances, so Excel can sum and sort them.
 *
 * @phpstan-type Column array{0: string, 1: string}
 * @phpstan-type Sheet array{name: string, columns: list<Column>, rows: iterable<list<mixed>>}
 * @phpstan-type Document array{title: string, slug: string, period: ReportPeriod, filters: list<array{0: string, 1: string}>, summary: list<array{0: string, 1: string|int}>, sheets: list<Sheet>}
 */
class ReportExport
{
    public const REPORTS = ['orders', 'customers', 'stock', 'employees'];

    private const TYPE_LABELS = [
        StockMovement::TYPE_SALE => 'Sale',
        StockMovement::TYPE_RESTOCK => 'Restock',
        StockMovement::TYPE_CORRECTION => 'Correction',
        StockMovement::TYPE_INITIAL => 'Opening stock',
        'adjustments' => 'Adjustments only (no sales)',
    ];

    public function __construct(private readonly ReportService $reports) {}

    /**
     * @param  array{employee_id?: ?int, search?: ?string, type?: ?string}  $filters
     * @return Document
     */
    public function build(string $report, ReportPeriod $period, array $filters): array
    {
        return match ($report) {
            'orders' => $this->orders($period, $filters),
            'customers' => $this->customers($period, $filters),
            'stock' => $this->stock($period, $filters),
            'employees' => $this->employees($period, $filters),
        };
    }

    /**
     * @return Document
     */
    private function orders(ReportPeriod $period, array $filters): array
    {
        $query = $this->reports->orders($period, $filters);
        $summary = $this->reports->orderSummary($query);
        $orders = $query->with(['customer', 'cashier', 'items.product'])->get();

        return $this->document('Order Report', 'order-report', $period, $filters, [
            ['Bills', $summary['orders']],
            ['Sales', $this->rupees($summary['grand_total'])],
            ['Before tax', $this->rupees($summary['subtotal'])],
            ['GST collected', $this->rupees($summary['tax_total'])],
            ['Average bill', $this->rupees($summary['average_bill'])],
        ], [
            // Each bill row (shaded) followed by the products billed on it.
            [
                'name' => 'Bills with products',
                'grouped' => true,
                'columns' => [
                    ['Bill no. / Product', 'text'], ['Date / Code', 'datetime'], ['Customer', 'text'], ['Billed by', 'text'],
                    ['Qty', 'int'], ['Unit price', 'money'], ['GST %', 'text'], ['Taxable', 'money'], ['GST', 'money'], ['Total', 'money'],
                ],
                'groups' => $orders->map(fn (Order $order) => [
                    'row' => [
                        $order->order_number,
                        $order->created_at,
                        trim($order->customer?->name.' '.($order->customer?->phone ?? '')),
                        $this->cashier($order),
                        (int) $order->items->sum('quantity'),
                        null,
                        null,
                        (float) $order->subtotal,
                        (float) $order->tax_total,
                        (float) $order->grand_total,
                    ],
                    'items' => $order->items->map(fn ($item) => [
                        $item->product?->name,
                        $item->product?->code,
                        null,
                        null,
                        (int) $item->quantity,
                        (float) $item->unit_price,
                        ((float) $item->tax_percent).'%',
                        (float) $item->line_subtotal,
                        (float) $item->line_tax,
                        (float) $item->line_total,
                    ])->all(),
                ]),
            ],
            [
                'name' => 'Bills',
                'only' => 'xlsx',
                'columns' => [
                    ['Bill no.', 'text'], ['Date', 'datetime'], ['Customer', 'text'], ['Mobile', 'text'],
                    ['Billed by', 'text'], ['Items', 'int'], ['Subtotal', 'money'], ['CGST', 'money'],
                    ['SGST', 'money'], ['IGST', 'money'], ['Grand total', 'money'],
                ],
                'rows' => $orders->map(fn (Order $order) => [
                    $order->order_number,
                    $order->created_at,
                    $order->customer?->name,
                    $order->customer?->phone ?? $order->customer?->email,
                    $this->cashier($order),
                    (int) $order->items->sum('quantity'),
                    (float) $order->subtotal,
                    (float) $order->cgst_amount,
                    (float) $order->sgst_amount,
                    (float) $order->igst_amount,
                    (float) $order->grand_total,
                ]),
            ],
            [
                'name' => 'Bill items',
                'only' => 'xlsx',
                'columns' => [
                    ['Bill no.', 'text'], ['Date', 'datetime'], ['Billed by', 'text'], ['Product', 'text'],
                    ['Code', 'text'], ['Qty', 'int'], ['Unit price', 'money'], ['GST %', 'text'],
                    ['Taxable', 'money'], ['GST', 'money'], ['Line total', 'money'],
                ],
                'rows' => $orders->flatMap(fn (Order $order) => $order->items->map(fn ($item) => [
                    $order->order_number,
                    $order->created_at,
                    $this->cashier($order),
                    $item->product?->name,
                    $item->product?->code,
                    (int) $item->quantity,
                    (float) $item->unit_price,
                    ((float) $item->tax_percent).'%',
                    (float) $item->line_subtotal,
                    (float) $item->line_tax,
                    (float) $item->line_total,
                ])),
            ],
        ]);
    }

    /**
     * @return Document
     */
    private function customers(ReportPeriod $period, array $filters): array
    {
        $query = $this->reports->customers($period, $filters);
        $summary = $this->reports->customerSummary($query, $period);

        return $this->document('Customer Report', 'customer-report', $period, $filters, [
            ['Customers', $summary['customers']],
            ['New customers', $summary['new_customers']],
            ['Bills', $summary['orders']],
            ['Total spent', $this->rupees($summary['total_spent'])],
        ], [[
            'name' => 'Customers',
            'columns' => [
                ['Customer', 'text'], ['Email', 'text'], ['Mobile', 'text'], ['Bills', 'int'],
                ['Average bill', 'money'], ['Total spent', 'money'], ['Last bill', 'datetime'], ['New', 'text'],
            ],
            'rows' => $query->get()->map(function (Customer $customer) use ($period) {
                $row = $this->reports->customerRow($customer, $period);

                return [
                    $row['name'], $row['email'], $row['phone'], $row['orders_count'],
                    (float) $row['average_bill'], (float) $row['total_spent'],
                    $row['last_order_at'] ? Carbon::parse($row['last_order_at']) : null,
                    $row['is_new'] ? 'Yes' : '',
                ];
            }),
        ]]);
    }

    /**
     * @return Document
     */
    private function stock(ReportPeriod $period, array $filters): array
    {
        $query = $this->reports->stock($period, $filters);
        $summary = $this->reports->stockSummary($query);

        return $this->document('Stock Report', 'stock-report', $period, $filters, [
            ['Stock changes', $summary['entries']],
            ['Units restocked', $summary['units_restocked']],
            ['Units sold', $summary['units_sold']],
            ['Corrections', $summary['corrections'].' (net '.sprintf('%+d', $summary['correction_units']).' units)'],
        ], [[
            'name' => 'Stock changes',
            'columns' => [
                ['Date', 'datetime'], ['Product', 'text'], ['Code', 'text'], ['Type', 'text'],
                ['Change', 'int'], ['Stock after', 'int'], ['Adjusted by', 'text'], ['Note / bill', 'text'],
            ],
            'rows' => $query->with(['product', 'user', 'order'])->get()->map(fn (StockMovement $m) => [
                $m->created_at,
                $m->product?->name ?? 'Deleted product',
                $m->product?->code,
                self::TYPE_LABELS[$m->type] ?? $m->type,
                $m->quantity,
                $m->stock_after,
                $m->user?->name ?? $m->user_name,
                $m->order?->order_number ?? $m->note,
            ]),
        ]]);
    }

    /**
     * @return Document
     */
    private function employees(ReportPeriod $period, array $filters): array
    {
        $employees = $this->reports->employees($period, $filters);
        $summary = $this->reports->employeeSummary($employees);
        $date = fn (?string $iso) => $iso ? Carbon::parse($iso) : null;

        return $this->document('Employee Report', 'employee-report', $period, $filters, [
            ['Employees billing', $summary['active_billers'].' of '.$summary['employees']],
            ['Bills', $summary['orders']],
            ['Sales', $this->rupees($summary['sales_total'])],
            ['Stock adjustments', $summary['adjustments']],
        ], [[
            'name' => 'Employees',
            'columns' => [
                ['Employee', 'text'], ['Email', 'text'], ['Role', 'text'], ['Status', 'text'],
                ['Bills', 'int'], ['Customers', 'int'], ['Average bill', 'money'], ['Sales', 'money'],
                ['Stock changes', 'int'], ['Units added', 'int'], ['Units removed', 'int'], ['Last bill', 'datetime'],
            ],
            'rows' => $employees->map(fn (array $e) => [
                $e['name'], $e['email'], $e['role'], $e['is_active'] ? 'Active' : 'Inactive',
                $e['orders_count'], $e['customers_count'], (float) $e['average_bill'], (float) $e['sales_total'],
                $e['adjustments_count'], $e['units_added'], $e['units_removed'], $date($e['last_bill_at']),
            ]),
        ]]);
    }

    /**
     * @return Document
     */
    private function document(string $title, string $slug, ReportPeriod $period, array $filters, array $summary, array $sheets): array
    {
        $applied = array_values(array_filter([
            ! empty($filters['employee_id']) ? ['Employee', User::whereKey($filters['employee_id'])->value('name') ?? '#'.$filters['employee_id']] : null,
            ! empty($filters['type']) ? ['Change type', self::TYPE_LABELS[$filters['type']] ?? $filters['type']] : null,
            ! empty($filters['search']) ? ['Search', $filters['search']] : null,
        ]));

        return compact('title', 'slug', 'period', 'summary', 'sheets') + ['filters' => $applied];
    }

    private function cashier(Order $order): ?string
    {
        return $order->cashier?->name ?? $order->created_by_name;
    }

    private function rupees(string $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }
}
