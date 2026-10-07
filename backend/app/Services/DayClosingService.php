<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashClosing;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The end-of-day summary for the cash counter: bills per payment mode and
 * cashier, every cash movement of the day from the Cash ledger, and the
 * cash the drawer should hold.
 */
class DayClosingService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(?int $storeId, CarbonInterface $date): array
    {
        $day = Carbon::parse($date)->startOfDay();

        $orders = Order::query()
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
            ->groupBy('created_by', 'created_by_name', 'payment_mode')
            ->selectRaw('created_by, created_by_name, payment_mode, COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total')
            ->get();

        $modes = array_fill_keys(Order::PAYMENT_MODES, ['bills' => 0, 'total' => 0]);
        $cashiers = [];
        foreach ($orders as $row) {
            $mode = $row->payment_mode;
            $cents = Money::toCents($row->total);
            $modes[$mode] ??= ['bills' => 0, 'total' => 0];
            $modes[$mode]['bills'] += (int) $row->bills;
            $modes[$mode]['total'] += $cents;

            $key = $row->created_by ?? 'name:'.$row->created_by_name;
            $cashiers[$key] ??= [
                'user_id' => $row->created_by,
                'name' => $row->created_by_name ?? 'Unknown',
                'bills' => 0,
                'total' => 0,
                'modes' => array_fill_keys(Order::PAYMENT_MODES, 0),
            ];
            $cashiers[$key]['bills'] += (int) $row->bills;
            $cashiers[$key]['total'] += $cents;
            $cashiers[$key]['modes'][$mode] = ($cashiers[$key]['modes'][$mode] ?? 0) + $cents;
        }

        // Every Cash ledger line of the day, by the kind of voucher behind it.
        $cash = Account::byCode(Account::CASH);
        $opening = $this->ledger->openingOn($cash, $storeId, $day);
        $entries = LedgerService::whereDateBetween(VoucherEntry::query(), 'date', $day, $day)
            ->where('account_id', $cash->id)
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->with('voucher')
            ->orderBy('id')
            ->get();

        $flow = array_fill_keys(['sales', 'refunds', 'receipts', 'payments', 'expenses', 'other_in', 'other_out'], 0);
        $vouchers = [];
        foreach ($entries as $entry) {
            $in = Money::toCents($entry->debit);
            $out = Money::toCents($entry->credit);
            $type = $entry->voucher->type;

            match ($type) {
                Voucher::SALES => $flow['sales'] += $in - $out,
                Voucher::CREDIT_NOTE => $flow['refunds'] += $out - $in,
                Voucher::RECEIPT => $flow['receipts'] += $in - $out,
                Voucher::PAYMENT => $flow['payments'] += $out - $in,
                Voucher::EXPENSE => $flow['expenses'] += $out - $in,
                default => [$flow['other_in'] += $in, $flow['other_out'] += $out],
            };

            if ($type !== Voucher::SALES) {
                $vouchers[] = [
                    'id' => $entry->voucher->id,
                    'number' => $entry->voucher->number,
                    'type' => $type,
                    'narration' => $entry->voucher->narration,
                    'in' => Money::format($in),
                    'out' => Money::format($out),
                ];
            }
        }

        $movement = $entries->sum(fn ($e) => Money::toCents($e->debit) - Money::toCents($e->credit));
        $expected = $opening + $movement;

        $format = fn (array $values) => array_map(fn ($v) => is_int($v) ? Money::format($v) : $v, $values);

        return [
            'date' => $day->toDateString(),
            'bills' => [
                'count' => array_sum(array_column($modes, 'bills')),
                'total' => Money::format(array_sum(array_column($modes, 'total'))),
            ],
            'modes' => collect($modes)->map(fn ($m, $mode) => ['mode' => $mode, 'bills' => $m['bills'], 'total' => Money::format($m['total'])])->values()->all(),
            'cashiers' => collect($cashiers)->sortBy('name')->map(fn ($c) => [
                ...$c,
                'total' => Money::format($c['total']),
                'modes' => array_map(fn (int $v) => Money::format($v), $c['modes']),
            ])->values()->all(),
            'cash' => [
                'opening' => Money::format($opening),
                ...$format($flow),
                'expected_closing' => Money::format($expected),
            ],
            'cash_vouchers' => $vouchers,
            'expected_cents' => $expected,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(?int $storeId, int $limit = 15): array
    {
        return CashClosing::query()
            ->with('store')
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->orderByDesc('date')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CashClosing $c) => $c->toReport())
            ->all();
    }
}
