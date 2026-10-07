<?php

namespace App\Services;

use App\Models\Account;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Trial balance, Profit & Loss and Balance Sheet, built only from the
 * generic ledger (accounts / voucher_entries) plus the stock valuation, so
 * they include whatever any module posted.
 *
 * Every amount leaves this class as a decimal string ("1234.50").
 */
class FinancialReportService
{
    public const GROUP_LABELS = [
        'cash' => 'Cash-in-hand',
        'bank' => 'Bank accounts',
        'receivable' => 'Sundry debtors',
        'current_asset' => 'Current assets',
        'fixed_asset' => 'Fixed assets',
        'duties_taxes' => 'Duties & taxes',
        'payable' => 'Sundry creditors',
        'current_liability' => 'Current liabilities',
        'loan' => 'Loans (liability)',
        'capital' => 'Capital account',
        'sales' => 'Sales accounts',
        'indirect_income' => 'Indirect income',
        'purchase' => 'Purchase accounts',
        'direct_expense' => 'Direct expenses',
        'indirect_expense' => 'Indirect expenses',
    ];

    public const TYPE_LABELS = [
        'asset' => 'Assets',
        'liability' => 'Liabilities',
        'equity' => 'Capital',
        'income' => 'Income',
        'expense' => 'Expenses',
    ];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly StockValuationService $stock,
    ) {}

    /**
     * Every account with a closing balance as at the end of $asOf, in a debit
     * or credit column. Opening balances that were entered unevenly show as a
     * "Difference in opening balances" line so the columns still agree.
     *
     * @return array<string, mixed>
     */
    public function trialBalance(?int $storeId, CarbonInterface $asOf): array
    {
        $accounts = $this->accounts();
        $balances = $this->ledger->balances($storeId, $asOf, $accounts);

        $rows = collect($balances)
            ->map(fn (int $cents, int $id) => ['account' => $accounts[$id], 'cents' => $cents])
            ->sortBy(fn (array $r) => [array_search($r['account']->type, Account::TYPES, true), $r['account']->code])
            ->values();

        $debit = $rows->sum(fn ($r) => max(0, $r['cents']));
        $credit = $rows->sum(fn ($r) => max(0, -$r['cents']));
        $difference = $debit - $credit;

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows->map(fn (array $r) => [
                ...$this->accountRow($r['account']),
                'debit' => $r['cents'] > 0 ? Money::format($r['cents']) : null,
                'credit' => $r['cents'] < 0 ? Money::format(-$r['cents']) : null,
            ])->all(),
            'opening_difference' => $difference === 0 ? null : [
                'debit' => $difference < 0 ? Money::format(-$difference) : null,
                'credit' => $difference > 0 ? Money::format($difference) : null,
            ],
            'totals' => [
                'debit' => Money::format(max($debit, $credit)),
                'credit' => Money::format(max($debit, $credit)),
            ],
            'closing_stock' => Money::format($this->stock->valueAt($storeId, $asOf)['value']),
        ];
    }

    /**
     * Trading and Profit & Loss account for a period, Tally style: debit and
     * credit sides with gross profit carried down to the P&L part.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(?int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        $accounts = $this->accounts();
        $totals = $this->ledger->totals($storeId, $from, $to);

        $opening = $this->stock->valueAt($storeId, $from->copy()->subDay());
        $closing = $this->stock->valueAt($storeId, $to);

        $sales = $this->groupLines($accounts, $totals, ['sales'], credit: true);
        $purchases = $this->groupLines($accounts, $totals, ['purchase']);
        $direct = $this->groupLines($accounts, $totals, ['direct_expense']);
        $indirectExpenses = $this->groupLines($accounts, $totals, ['indirect_expense']);
        $indirectIncome = $this->groupLines($accounts, $totals, ['indirect_income'], credit: true);

        $gross = $sales['total'] + $closing['value'] - $opening['value'] - $purchases['total'] - $direct['total'];
        $net = $gross + $indirectIncome['total'] - $indirectExpenses['total'];

        $tradingDebit = [
            $this->line('Opening stock', $opening['value']),
            $this->line('Purchases (net of returns)', $purchases['total'], $purchases['lines']),
            $this->line('Direct expenses', $direct['total'], $direct['lines']),
        ];
        $tradingCredit = [
            $this->line('Sales (net of returns)', $sales['total'], $sales['lines']),
            $this->line('Closing stock', $closing['value']),
        ];
        if ($gross >= 0) {
            $tradingDebit[] = $this->line('Gross profit c/o', $gross, bold: true);
        } else {
            $tradingCredit[] = $this->line('Gross loss c/o', -$gross, bold: true);
        }
        $tradingTotal = max(
            $opening['value'] + $purchases['total'] + $direct['total'] + max(0, $gross),
            $sales['total'] + $closing['value'] + max(0, -$gross),
        );

        $plDebit = [];
        $plCredit = [];
        if ($gross >= 0) {
            $plCredit[] = $this->line('Gross profit b/f', $gross, bold: true);
        } else {
            $plDebit[] = $this->line('Gross loss b/f', -$gross, bold: true);
        }
        $plDebit[] = $this->line('Indirect expenses', $indirectExpenses['total'], $indirectExpenses['lines']);
        $plCredit[] = $this->line('Indirect income', $indirectIncome['total'], $indirectIncome['lines']);
        if ($net >= 0) {
            $plDebit[] = $this->line('Net profit', $net, bold: true);
        } else {
            $plCredit[] = $this->line('Net loss', -$net, bold: true);
        }
        $plTotal = max(max(0, -$gross) + $indirectExpenses['total'] + max(0, $net), max(0, $gross) + $indirectIncome['total'] + max(0, -$net));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_stock' => Money::format($opening['value']),
            'closing_stock' => Money::format($closing['value']),
            'purchases' => Money::format($purchases['total']),
            'direct_expenses' => Money::format($direct['total']),
            'sales' => Money::format($sales['total']),
            'indirect_expenses' => Money::format($indirectExpenses['total']),
            'indirect_income' => Money::format($indirectIncome['total']),
            'gross_profit' => Money::format($gross),
            'net_profit' => Money::format($net),
            'trading' => ['debit' => $tradingDebit, 'credit' => $tradingCredit, 'total' => Money::format($tradingTotal)],
            'profit_loss' => ['debit' => $plDebit, 'credit' => $plCredit, 'total' => Money::format($plTotal)],
            'valuation_basis' => $closing['valuation_basis'],
        ];
    }

    /**
     * Balance sheet as at the end of $asOf. Profit is everything earned since
     * the books began (income − expenses) plus the closing stock, which is
     * also shown as an asset; a non-zero `difference` means the opening
     * balances entered in the chart of accounts do not agree.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(?int $storeId, CarbonInterface $asOf): array
    {
        $accounts = $this->accounts();
        $balances = $this->ledger->balances($storeId, $asOf, $accounts);
        $stock = $this->stock->valueAt($storeId, $asOf);

        $sections = [
            'assets' => array_fill_keys(['fixed_asset', 'stock', 'receivable', 'cash', 'bank', 'duties_taxes', 'current_asset'], []),
            'liabilities' => array_fill_keys(['capital', 'profit', 'loan', 'payable', 'duties_taxes', 'current_liability'], []),
        ];

        $earned = 0;
        foreach ($balances as $id => $cents) {
            $account = $accounts[$id];

            if (in_array($account->type, ['income', 'expense'], true)) {
                $earned -= $cents;

                continue;
            }

            // A customer who paid in advance is a liability; an advance to a
            // supplier is an asset.
            [$side, $key] = match (true) {
                $account->group === 'receivable' && $cents < 0 => ['liabilities', 'payable'],
                $account->group === 'payable' && $cents > 0 => ['assets', 'receivable'],
                $account->type === 'asset' => ['assets', array_key_exists($account->group, $sections['assets']) ? $account->group : 'current_asset'],
                $account->type === 'equity' => ['liabilities', 'capital'],
                default => ['liabilities', array_key_exists($account->group, $sections['liabilities']) ? $account->group : 'current_liability'],
            };

            $sections[$side][$key][] = [
                ...$this->accountRow($account),
                'cents' => $side === 'assets' ? $cents : -$cents,
            ];
        }

        $sections['assets']['stock'][] = ['id' => null, 'code' => null, 'name' => 'Closing stock (valued)', 'cents' => $stock['value']];
        $sections['liabilities']['profit'][] = ['id' => null, 'code' => null, 'name' => 'Profit & Loss A/c (accumulated)', 'cents' => $earned + $stock['value']];

        $labels = [
            'assets' => [
                'fixed_asset' => 'Fixed assets', 'stock' => 'Stock-in-hand', 'receivable' => 'Sundry debtors',
                'cash' => 'Cash-in-hand', 'bank' => 'Bank accounts', 'duties_taxes' => 'Input GST & taxes', 'current_asset' => 'Other current assets',
            ],
            'liabilities' => [
                'capital' => 'Capital account', 'profit' => 'Profit & Loss A/c', 'loan' => 'Loans (liability)',
                'payable' => 'Sundry creditors', 'duties_taxes' => 'Output GST & taxes (net)', 'current_liability' => 'Current liabilities',
            ],
        ];

        $result = ['as_of' => $asOf->toDateString()];
        $totals = [];
        foreach ($sections as $side => $groups) {
            $totals[$side] = 0;
            $result[$side] = [];
            foreach ($groups as $key => $rows) {
                $rows = array_values(array_filter($rows, fn ($r) => $r['cents'] !== 0));
                if ($rows === []) {
                    continue;
                }
                usort($rows, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));
                $sum = array_sum(array_column($rows, 'cents'));
                $totals[$side] += $sum;
                $result[$side][] = [
                    'key' => $key,
                    'label' => $labels[$side][$key],
                    'total' => Money::format($sum),
                    'accounts' => array_map(fn ($r) => [...array_diff_key($r, ['cents' => 1]), 'amount' => Money::format($r['cents'])], $rows),
                ];
            }
        }

        return [
            ...$result,
            'total_assets' => Money::format($totals['assets']),
            'total_liabilities' => Money::format($totals['liabilities']),
            'difference' => Money::format($totals['assets'] - $totals['liabilities']),
            'closing_stock' => Money::format($stock['value']),
            'accumulated_profit' => Money::format($earned + $stock['value']),
            'valuation_basis' => $stock['valuation_basis'],
        ];
    }

    /**
     * @return Collection<int, Account>
     */
    private function accounts(): Collection
    {
        return Account::query()->get()->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function accountRow(Account $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'group' => $account->group,
        ];
    }

    /**
     * Net movement of the accounts in some groups: debit − credit, or
     * credit − debit for income groups.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, array{debit: int, credit: int}>  $totals
     * @param  list<string>  $groups
     * @return array{total: int, lines: list<array<string, mixed>>}
     */
    private function groupLines(Collection $accounts, array $totals, array $groups, bool $credit = false): array
    {
        $lines = [];
        $total = 0;
        foreach ($accounts->whereIn('group', $groups)->sortBy('code') as $account) {
            $t = $totals[$account->id] ?? null;
            if ($t === null) {
                continue;
            }
            $net = $credit ? $t['credit'] - $t['debit'] : $t['debit'] - $t['credit'];
            if ($net === 0) {
                continue;
            }
            $total += $net;
            $lines[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amount' => Money::format($net)];
        }

        return ['total' => $total, 'lines' => $lines];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function line(string $label, int $cents, array $lines = [], bool $bold = false): array
    {
        return ['label' => $label, 'amount' => Money::format($cents), 'lines' => $lines, 'bold' => $bold];
    }
}
