<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Order;
use App\Models\Store;
use App\Models\Voucher;
use App\Support\Money;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly GST returns in the shape of the GSTN forms:
 *   GSTR-1  outward supplies from the bills (B2B, B2C, HSN, credit notes, documents)
 *   GSTR-3B output tax and input tax credit from the GST ledgers.
 *
 * B2B needs orders.customer_gstin (added by the tax-invoice module); without
 * that column every bill counts as B2C.
 */
class GstReportService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthRange(string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * @return array<string, mixed>
     */
    public function gstr1(?int $storeId, string $month): array
    {
        [$from, $to] = self::monthRange($month);
        $hasGstin = Schema::hasColumn('orders', 'customer_gstin');
        $hasPlace = Schema::hasColumn('orders', 'place_of_supply');

        $orders = fn () => Order::query()
            ->when($storeId, fn ($q, int $id) => $q->where('orders.store_id', $id))
            ->whereBetween('orders.created_at', [$from, $to]);

        $isB2b = function (Builder $q) {
            $q->whereNotNull('orders.customer_gstin')->where('orders.customer_gstin', '<>', '');
        };

        // B2B: one row per invoice to a registered buyer.
        $b2b = [];
        if ($hasGstin) {
            $stores = Store::query()->get()->keyBy('id');
            $b2b = $orders()->where($isB2b)->with('customer')->orderBy('id')->get()->map(fn (Order $o) => [
                'id' => $o->id,
                'invoice_number' => $o->invoice_number,
                'date' => $o->created_at->toDateString(),
                'customer' => $o->customer?->name,
                'gstin' => $o->customer_gstin,
                'place_of_supply' => ($hasPlace ? $o->place_of_supply : null) ?: $this->storeState($stores[$o->store_id] ?? null),
                'interstate' => (bool) $o->is_interstate,
                'taxable' => $o->subtotal,
                'igst' => $o->igst_amount,
                'cgst' => $o->cgst_amount,
                'sgst' => $o->sgst_amount,
                'total' => $o->grand_total,
            ])->all();
        }

        // B2C (small): rate-wise and intra / inter state totals of the other bills.
        $b2c = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->when($storeId, fn ($q, int $id) => $q->where('orders.store_id', $id))
            ->whereBetween('orders.created_at', [$from, $to])
            ->when($hasGstin, fn ($q) => $q->where(fn ($w) => $w->whereNull('orders.customer_gstin')->orWhere('orders.customer_gstin', '')))
            ->groupBy('order_items.tax_percent', 'orders.is_interstate')
            ->selectRaw('order_items.tax_percent as rate, orders.is_interstate as interstate, COUNT(DISTINCT orders.id) as bills,
                COALESCE(SUM(order_items.line_subtotal), 0) as taxable, COALESCE(SUM(order_items.igst_amount), 0) as igst,
                COALESCE(SUM(order_items.cgst_amount), 0) as cgst, COALESCE(SUM(order_items.sgst_amount), 0) as sgst,
                COALESCE(SUM(order_items.line_total), 0) as total')
            ->get()
            ->map(fn ($r) => [
                'rate' => Money::format(Money::toCents($r->rate)),
                'interstate' => (bool) $r->interstate,
                'supply_type' => $r->interstate ? 'Inter-state' : 'Intra-state',
                'bills' => (int) $r->bills,
                ...$this->amounts($r),
            ])
            ->sortBy(fn ($r) => [(float) $r['rate'], $r['interstate']])
            ->values()
            ->all();

        // HSN summary over every bill.
        $hsn = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->when($storeId, fn ($q, int $id) => $q->where('orders.store_id', $id))
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('products.hsn_code', 'products.unit', 'order_items.tax_percent')
            ->selectRaw('products.hsn_code as hsn, products.unit as unit, order_items.tax_percent as rate, MIN(products.name) as description,
                COALESCE(SUM(order_items.quantity), 0) as quantity,
                COALESCE(SUM(order_items.line_subtotal), 0) as taxable, COALESCE(SUM(order_items.igst_amount), 0) as igst,
                COALESCE(SUM(order_items.cgst_amount), 0) as cgst, COALESCE(SUM(order_items.sgst_amount), 0) as sgst,
                COALESCE(SUM(order_items.line_total), 0) as total')
            ->get()
            ->map(fn ($r) => [
                'hsn' => $r->hsn ?: null,
                'description' => $r->description,
                'uqc' => strtoupper($r->unit ?: 'pcs'),
                'rate' => Money::format(Money::toCents($r->rate)),
                'quantity' => (int) $r->quantity,
                ...$this->amounts($r),
            ])
            ->sortBy(fn ($r) => [$r['hsn'] ?? 'ZZZZ', (float) $r['rate']])
            ->values()
            ->all();

        // Credit notes (sales returns) posted in the month, from their vouchers.
        $codes = [Account::SALES_RETURNS, Account::OUTPUT_CGST, Account::OUTPUT_SGST, Account::OUTPUT_IGST];
        $ids = Account::query()->whereIn('code', $codes)->pluck('id', 'code');
        $creditNotes = LedgerService::whereDateBetween(Voucher::query(), 'date', $from, $to)
            ->where('type', Voucher::CREDIT_NOTE)
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->with('entries')
            ->orderBy('date')->orderBy('id')
            ->get()
            ->map(function (Voucher $v) use ($ids) {
                $debit = fn (string $code) => $v->entries->where('account_id', $ids[$code] ?? 0)
                    ->sum(fn ($e) => Money::toCents($e->debit) - Money::toCents($e->credit));

                return [
                    'id' => $v->id,
                    'number' => $v->number,
                    'date' => $v->date->toDateString(),
                    'narration' => $v->narration,
                    'taxable' => Money::format($debit(Account::SALES_RETURNS)),
                    'igst' => Money::format($debit(Account::OUTPUT_IGST)),
                    'cgst' => Money::format($debit(Account::OUTPUT_CGST)),
                    'sgst' => Money::format($debit(Account::OUTPUT_SGST)),
                    'total' => $v->amount,
                ];
            })
            ->all();

        $invoiceNumbers = $orders()->whereNotNull('invoice_number')->orderBy('id')->pluck('invoice_number');
        $documents = [
            $this->documentRow('Invoices for outward supply', $invoiceNumbers->all()),
            $this->documentRow('Credit notes', array_column($creditNotes, 'number')),
        ];

        $sum = fn (array $rows, string $key) => Money::format(array_sum(array_map(fn ($r) => Money::toCents($r[$key]), $rows)));

        return [
            'month' => $month,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'b2b_available' => $hasGstin,
            'b2b' => $b2b,
            'b2c' => $b2c,
            'hsn' => $hsn,
            'credit_notes' => $creditNotes,
            'documents' => $documents,
            'totals' => [
                'b2b' => ['count' => count($b2b), 'taxable' => $sum($b2b, 'taxable'), 'tax' => $this->taxSum($b2b)],
                'b2c' => ['taxable' => $sum($b2c, 'taxable'), 'tax' => $this->taxSum($b2c)],
                'hsn' => ['taxable' => $sum($hsn, 'taxable'), 'tax' => $this->taxSum($hsn), 'total' => $sum($hsn, 'total')],
                'credit_notes' => ['count' => count($creditNotes), 'taxable' => $sum($creditNotes, 'taxable'), 'tax' => $this->taxSum($creditNotes)],
            ],
        ];
    }

    /**
     * GSTR-3B summary from the ledgers: output tax (credits − debits on the
     * Output GST accounts), eligible ITC (debits − credits on Input GST) and
     * the net tax per head.
     *
     * @return array<string, mixed>
     */
    public function gstr3b(?int $storeId, string $month): array
    {
        [$from, $to] = self::monthRange($month);

        $accounts = Account::query()->get(['id', 'code', 'group'])->keyBy('code');
        $totals = $this->ledger->totals($storeId, $from, $to);
        $net = fn (string $code, bool $credit) => ($t = $totals[$accounts[$code]->id ?? 0] ?? null)
            ? ($credit ? $t['credit'] - $t['debit'] : $t['debit'] - $t['credit'])
            : 0;

        $taxable = 0;
        foreach ($accounts->where('group', 'sales') as $account) {
            $t = $totals[$account->id] ?? null;
            $taxable += $t ? $t['credit'] - $t['debit'] : 0;
        }

        $heads = [
            'igst' => [Account::OUTPUT_IGST, Account::INPUT_IGST],
            'cgst' => [Account::OUTPUT_CGST, Account::INPUT_CGST],
            'sgst' => [Account::OUTPUT_SGST, Account::INPUT_SGST],
        ];
        $rows = [];
        $sums = ['output' => 0, 'itc' => 0, 'net' => 0, 'payable' => 0, 'carry_forward' => 0];
        foreach ($heads as $head => [$output, $input]) {
            $out = $net($output, true);
            $itc = $net($input, false);
            $balance = $out - $itc;
            $rows[$head] = [
                'output' => Money::format($out),
                'itc' => Money::format($itc),
                'net' => Money::format($balance),
                'payable' => Money::format(max(0, $balance)),
                'carry_forward' => Money::format(max(0, -$balance)),
            ];
            $sums['output'] += $out;
            $sums['itc'] += $itc;
            $sums['net'] += $balance;
            $sums['payable'] += max(0, $balance);
            $sums['carry_forward'] += max(0, -$balance);
        }

        // Zero-rated / exempt lines on the bills, for table 3.1(c).
        $nilRated = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->when($storeId, fn ($q, int $id) => $q->where('orders.store_id', $id))
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('order_items.tax_percent', '=', 0)
            ->sum('order_items.line_subtotal');

        return [
            'month' => $month,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'outward' => [
                'taxable' => Money::format($taxable),
                'igst' => $rows['igst']['output'],
                'cgst' => $rows['cgst']['output'],
                'sgst' => $rows['sgst']['output'],
                'nil_rated' => Money::format(Money::toCents($nilRated)),
            ],
            'itc' => ['igst' => $rows['igst']['itc'], 'cgst' => $rows['cgst']['itc'], 'sgst' => $rows['sgst']['itc'], 'total' => Money::format($sums['itc'])],
            'heads' => $rows,
            'totals' => array_map(fn (int $c) => Money::format($c), $sums),
        ];
    }

    /**
     * GSTR-1 as a ReportSpreadsheet document (one sheet per section).
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function gstr1Document(array $report, string $storeName): array
    {
        $money = fn ($v) => (float) $v;

        return [
            'title' => "GSTR-1 {$report['month']}",
            'period' => ReportPeriod::resolve('custom', $report['from'], $report['to']),
            'filters' => [['Store', $storeName]],
            'summary' => [
                ['B2B invoices', $report['totals']['b2b']['count']],
                ['B2B taxable value', $money($report['totals']['b2b']['taxable'])],
                ['B2C taxable value', $money($report['totals']['b2c']['taxable'])],
                ['Credit notes', $report['totals']['credit_notes']['count']],
            ],
            'sheets' => [
                [
                    'name' => 'B2B',
                    'columns' => [['GSTIN', 'text'], ['Customer', 'text'], ['Invoice no', 'text'], ['Date', 'text'], ['Place of supply', 'text'],
                        ['Taxable value', 'money'], ['IGST', 'money'], ['CGST', 'money'], ['SGST', 'money'], ['Invoice value', 'money']],
                    'rows' => array_map(fn ($r) => [$r['gstin'], $r['customer'], $r['invoice_number'], $r['date'], $r['place_of_supply'],
                        $money($r['taxable']), $money($r['igst']), $money($r['cgst']), $money($r['sgst']), $money($r['total'])], $report['b2b']),
                ],
                [
                    'name' => 'B2C',
                    'columns' => [['Rate %', 'text'], ['Supply', 'text'], ['Bills', 'int'], ['Taxable value', 'money'],
                        ['IGST', 'money'], ['CGST', 'money'], ['SGST', 'money'], ['Total', 'money']],
                    'rows' => array_map(fn ($r) => [$r['rate'], $r['supply_type'], $r['bills'], $money($r['taxable']),
                        $money($r['igst']), $money($r['cgst']), $money($r['sgst']), $money($r['total'])], $report['b2c']),
                ],
                [
                    'name' => 'HSN',
                    'columns' => [['HSN', 'text'], ['Description', 'text'], ['UQC', 'text'], ['Rate %', 'text'], ['Quantity', 'int'],
                        ['Taxable value', 'money'], ['IGST', 'money'], ['CGST', 'money'], ['SGST', 'money'], ['Total value', 'money']],
                    'rows' => array_map(fn ($r) => [$r['hsn'] ?? '', $r['description'], $r['uqc'], $r['rate'], $r['quantity'],
                        $money($r['taxable']), $money($r['igst']), $money($r['cgst']), $money($r['sgst']), $money($r['total'])], $report['hsn']),
                ],
                [
                    'name' => 'Credit notes',
                    'columns' => [['Note no', 'text'], ['Date', 'text'], ['Narration', 'text'], ['Taxable value', 'money'],
                        ['IGST', 'money'], ['CGST', 'money'], ['SGST', 'money'], ['Note value', 'money']],
                    'rows' => array_map(fn ($r) => [$r['number'], $r['date'], $r['narration'] ?? '', $money($r['taxable']),
                        $money($r['igst']), $money($r['cgst']), $money($r['sgst']), $money($r['total'])], $report['credit_notes']),
                ],
                [
                    'name' => 'Documents',
                    'columns' => [['Document', 'text'], ['From', 'text'], ['To', 'text'], ['Total issued', 'int']],
                    'rows' => array_map(fn ($r) => [$r['document'], $r['first'] ?? '', $r['last'] ?? '', $r['count']], $report['documents']),
                ],
            ],
        ];
    }

    private function storeState(?Store $store): ?string
    {
        if ($store === null) {
            return null;
        }

        return trim(($store->state_code ? $store->state_code.'-' : '').($store->state ?? ''), '-') ?: null;
    }

    /**
     * @return array<string, string>
     */
    private function amounts(object $row): array
    {
        return [
            'taxable' => Money::format(Money::toCents($row->taxable)),
            'igst' => Money::format(Money::toCents($row->igst)),
            'cgst' => Money::format(Money::toCents($row->cgst)),
            'sgst' => Money::format(Money::toCents($row->sgst)),
            'total' => Money::format(Money::toCents($row->total)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function taxSum(array $rows): string
    {
        return Money::format(array_sum(array_map(
            fn ($r) => Money::toCents($r['igst']) + Money::toCents($r['cgst']) + Money::toCents($r['sgst']),
            $rows,
        )));
    }

    /**
     * @param  list<string>  $numbers  in issue order
     * @return array{document: string, first: ?string, last: ?string, count: int}
     */
    private function documentRow(string $label, array $numbers): array
    {
        return [
            'document' => $label,
            'first' => $numbers[0] ?? null,
            'last' => $numbers === [] ? null : $numbers[count($numbers) - 1],
            'count' => count($numbers),
        ];
    }
}
