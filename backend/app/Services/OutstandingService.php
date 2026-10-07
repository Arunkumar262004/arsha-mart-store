<?php

namespace App\Services;

use App\Models\Account;
use App\Models\VoucherEntry;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Amounts customers owe us (receivable) or we owe suppliers (payable), from
 * the party ledgers, with FIFO ageing: every payment settles the oldest
 * unpaid bill first, and what is left is aged by the date it was billed.
 */
class OutstandingService
{
    public const BUCKETS = ['0_30', '31_60', '61_90', '90_plus'];

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>, count: int}
     */
    public function report(?int $storeId, string $type, CarbonInterface $asOf, ?string $search = null): array
    {
        $asOf = Carbon::parse($asOf)->startOfDay();
        // Receivables grow with debits, payables with credits.
        $sign = $type === 'receivable' ? 1 : -1;

        $accounts = Account::query()
            ->whereNotNull('party_type')
            ->where('group', $type)
            ->when($search, fn ($q, string $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($s).'%'])
                ->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($s).'%'])))
            ->get()
            ->keyBy('id');

        $parties = $this->parties($accounts);

        $entries = VoucherEntry::query()
            ->whereIn('account_id', $accounts->keys())
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            // "< next day": SQLite stores the date cast as "Y-m-d 00:00:00", which "<= Y-m-d" would skip.
            ->where('date', '<', $asOf->copy()->addDay()->toDateString())
            ->orderBy('date')->orderBy('id')
            ->get(['account_id', 'date', 'debit', 'credit'])
            ->groupBy('account_id');

        $rows = [];
        $totals = array_fill_keys([...self::BUCKETS, 'balance', 'advance'], 0);

        foreach ($accounts as $account) {
            $movements = [];
            $opening = $storeId === null ? $sign * Money::toCents($account->opening_balance) : 0;
            if ($opening !== 0) {
                $first = $entries->get($account->id)?->first()?->date;
                $movements[] = [Carbon::parse($first && $first->lt($account->created_at) ? $first : $account->created_at)->startOfDay(), $opening];
            }
            foreach ($entries->get($account->id, collect()) as $entry) {
                $movements[] = [$entry->date, $sign * (Money::toCents($entry->debit) - Money::toCents($entry->credit))];
            }

            $aged = $this->age($movements, $asOf);
            if ($aged['balance'] === 0) {
                continue;
            }

            $party = $parties[$account->id] ?? null;
            $row = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $party?->name ?? $account->name,
                'phone' => $party?->phone ?? null,
                'email' => $party?->email ?? null,
                'balance' => Money::format($aged['balance']),
                'side' => LedgerService::side($sign * $aged['balance']),
                'advance' => Money::format($aged['advance']),
                'last_transaction' => $entries->get($account->id)?->last()?->date?->toDateString(),
                'buckets' => array_map(fn (int $c) => Money::format($c), $aged['buckets']),
            ];
            $rows[] = $row;

            $totals['balance'] += $aged['balance'];
            $totals['advance'] += $aged['advance'];
            foreach (self::BUCKETS as $bucket) {
                $totals[$bucket] += $aged['buckets'][$bucket];
            }
        }

        usort($rows, fn ($a, $b) => Money::toCents($b['balance']) <=> Money::toCents($a['balance']));

        return [
            'rows' => $rows,
            'totals' => array_map(fn (int $c) => Money::format($c), $totals),
            'count' => count($rows),
        ];
    }

    /**
     * FIFO ageing of signed movements (positive = billed, negative = paid).
     *
     * @param  list<array{0: CarbonInterface, 1: int}>  $movements  in date order
     * @return array{balance: int, advance: int, buckets: array<string, int>}
     */
    public function age(array $movements, CarbonInterface $asOf): array
    {
        $open = [];   // unpaid bills: [date, cents], oldest first
        $advance = 0; // paid in excess, used up by the next bills

        foreach ($movements as [$date, $cents]) {
            if ($cents > 0) {
                $used = min($advance, $cents);
                $advance -= $used;
                if ($cents - $used > 0) {
                    $open[] = [$date, $cents - $used];
                }

                continue;
            }

            $pay = -$cents;
            while ($pay > 0 && $open !== []) {
                $take = min($pay, $open[0][1]);
                $open[0][1] -= $take;
                $pay -= $take;
                if ($open[0][1] === 0) {
                    array_shift($open);
                }
            }
            $advance += $pay;
        }

        $buckets = array_fill_keys(self::BUCKETS, 0);
        foreach ($open as [$date, $cents]) {
            $days = (int) Carbon::parse($date)->startOfDay()->diffInDays($asOf, false);
            $bucket = match (true) {
                $days <= 30 => '0_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$bucket] += $cents;
        }

        return [
            'balance' => array_sum(array_column($open, 1)) - $advance,
            'advance' => $advance,
            'buckets' => $buckets,
        ];
    }

    /**
     * The customer / supplier behind each ledger, skipping party types whose
     * model class is not available.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, Model>
     */
    private function parties(Collection $accounts): array
    {
        $parties = [];
        foreach ($accounts->groupBy('party_type') as $class => $group) {
            if (! class_exists($class)) {
                continue;
            }
            $models = $class::query()->whereKey($group->pluck('party_id'))->get()->keyBy(fn ($m) => $m->getKey());
            foreach ($group as $account) {
                if ($models->has($account->party_id)) {
                    $parties[$account->id] = $models[$account->party_id];
                }
            }
        }

        return $parties;
    }
}
