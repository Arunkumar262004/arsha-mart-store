<?php

namespace App\Services;

use App\Models\Account;
use App\Models\VoucherEntry;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only sums over voucher_entries shared by the accounts reports. All
 * amounts are integer cents; a signed balance is positive for debit.
 *
 * Opening balances belong to the business as a whole, so they are counted
 * only when not filtering by store (the same rule as AccountingService).
 */
class LedgerService
{
    /**
     * Debit and credit totals per account for entries in [from, to] (either
     * end optional), in a store or all stores.
     *
     * @param  list<int>|null  $accountIds
     * @return array<int, array{debit: int, credit: int}>
     */
    public function totals(?int $storeId, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?array $accountIds = null): array
    {
        $query = VoucherEntry::query()
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->when($accountIds !== null, fn ($q) => $q->whereIn('account_id', $accountIds));

        return self::whereDateBetween($query, 'date', $from, $to)
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->account_id => [
                'debit' => Money::toCents($row->debit),
                'credit' => Money::toCents($row->credit),
            ]])
            ->all();
    }

    /**
     * Closing balance of every account that has one, as at the end of $upTo
     * (or today), opening balances included in all-stores mode.
     *
     * @param  Collection<int, Account>|null  $accounts  keyed by id; loaded when null
     * @return array<int, int> account id => signed cents
     */
    public function balances(?int $storeId, ?CarbonInterface $upTo = null, ?Collection $accounts = null): array
    {
        $balances = [];

        if ($storeId === null) {
            $accounts ??= Account::query()->get(['id', 'opening_balance'])->keyBy('id');
            foreach ($accounts as $account) {
                $opening = Money::toCents($account->opening_balance);
                if ($opening !== 0) {
                    $balances[$account->id] = $opening;
                }
            }
        }

        foreach ($this->totals($storeId, null, $upTo) as $id => $t) {
            $balances[$id] = ($balances[$id] ?? 0) + $t['debit'] - $t['credit'];
        }

        return array_filter($balances, fn (int $cents) => $cents !== 0);
    }

    /**
     * Balance of one account at the start of a day (entries before $date).
     */
    public function openingOn(Account $account, ?int $storeId, CarbonInterface $date): int
    {
        $totals = $this->totals($storeId, null, Carbon::parse($date)->subDay(), [$account->id])[$account->id] ?? ['debit' => 0, 'credit' => 0];
        $opening = $storeId === null ? Money::toCents($account->opening_balance) : 0;

        return $opening + $totals['debit'] - $totals['credit'];
    }

    /**
     * Limit a date column to whole days [from, to]. The upper bound is "before
     * the next day" because SQLite keeps date-cast values as "Y-m-d 00:00:00"
     * strings, which a plain `<= 'Y-m-d'` would miss; PostgreSQL is fine either way.
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereDateBetween($query, string $column, ?CarbonInterface $from, ?CarbonInterface $to)
    {
        if ($from) {
            $query->where($column, '>=', $from->toDateString());
        }
        if ($to) {
            $query->where($column, '<', Carbon::parse($to)->addDay()->toDateString());
        }

        return $query;
    }

    /**
     * "1,234.50 Dr" style side of a signed balance.
     */
    public static function side(int $cents): ?string
    {
        return $cents === 0 ? null : ($cents > 0 ? 'Dr' : 'Cr');
    }

    /**
     * 1 April of the financial year containing $date (India: April to March).
     */
    public static function financialYearStart(?CarbonInterface $date = null): Carbon
    {
        $date = Carbon::parse($date ?? now());

        return Carbon::create($date->month < 4 ? $date->year - 1 : $date->year, 4, 1)->startOfDay();
    }
}
