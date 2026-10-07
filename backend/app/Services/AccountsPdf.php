<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * PDF versions of the financial statements and GSTR-3B, from the arrays
 * FinancialReportService / GstReportService return.
 */
class AccountsPdf
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function trialBalance(array $report, string $store): string
    {
        $rows = array_map(fn ($r) => ['cells' => ["{$r['code']} · {$r['name']}", $r['debit'], $r['credit']]], $report['rows']);
        if ($report['opening_difference']) {
            $rows[] = ['cells' => ['Difference in opening balances', $report['opening_difference']['debit'], $report['opening_difference']['credit']]];
        }
        $rows[] = ['cells' => ['Total', $report['totals']['debit'], $report['totals']['credit']], 'bold' => true];

        return $this->render('Trial Balance', 'As at '.$this->day($report['as_of']), $store, [[
            'type' => 'table',
            'columns' => [['Account', 'left'], ['Debit', 'right'], ['Credit', 'right']],
            'rows' => $rows,
        ]], ['Closing stock (memo, not a ledger balance): '.number_format((float) $report['closing_stock'], 2)]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function profitAndLoss(array $report, string $store): string
    {
        $side = fn (array $lines) => collect($lines)->flatMap(fn ($line) => [
            ['label' => $line['label'], 'amount' => $line['amount'], 'bold' => $line['bold']],
            ...array_map(fn ($l) => ['label' => $l['name'], 'amount' => $l['amount'], 'indent' => true], $line['lines']),
        ])->all();

        $blocks = [];
        foreach (['trading' => 'Trading account', 'profit_loss' => 'Profit & Loss account'] as $key => $heading) {
            $blocks[] = [
                'type' => 'sides',
                'heading' => $heading,
                'left' => ['title' => 'Particulars (Dr)', 'rows' => $side($report[$key]['debit'])],
                'right' => ['title' => 'Particulars (Cr)', 'rows' => $side($report[$key]['credit'])],
                'total' => $report[$key]['total'],
            ];
        }

        return $this->render('Profit & Loss', $this->day($report['from']).' – '.$this->day($report['to']), $store, $blocks,
            ['Stock valuation: '.$report['valuation_basis'].'.']);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function balanceSheet(array $report, string $store): string
    {
        $side = fn (array $groups) => collect($groups)->flatMap(fn ($group) => [
            ['label' => $group['label'], 'amount' => $group['total'], 'bold' => true],
            ...array_map(fn ($a) => ['label' => $a['name'], 'amount' => $a['amount'], 'indent' => true], $group['accounts']),
        ])->all();

        $notes = ['Stock valuation: '.$report['valuation_basis'].'.'];
        if ((float) $report['difference'] !== 0.0) {
            $notes[] = 'Difference of '.$report['difference'].' (assets − liabilities): the opening balances in the chart of accounts do not agree.';
        }

        return $this->render('Balance Sheet', 'As at '.$this->day($report['as_of']), $store, [[
            'type' => 'sides',
            'left' => ['title' => 'Liabilities & capital', 'rows' => $side($report['liabilities'])],
            'right' => ['title' => 'Assets', 'rows' => $side($report['assets'])],
            'total' => max((float) $report['total_assets'], (float) $report['total_liabilities']),
        ]], $notes);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function gstr3b(array $report, string $store): string
    {
        $o = $report['outward'];
        $heads = $report['heads'];

        return $this->render('GSTR-3B summary', Carbon::parse($report['from'])->format('F Y'), $store, [
            [
                'type' => 'table',
                'heading' => '3.1 Outward supplies',
                'columns' => [['Nature of supplies', 'left'], ['Taxable value', 'right'], ['IGST', 'right'], ['CGST', 'right'], ['SGST', 'right']],
                'rows' => [
                    ['cells' => ['(a) Outward taxable supplies (net of returns)', $o['taxable'], $o['igst'], $o['cgst'], $o['sgst']]],
                    ['cells' => ['(c) Nil rated / exempted (from bills)', $o['nil_rated'], null, null, null]],
                ],
            ],
            [
                'type' => 'table',
                'heading' => '4. Eligible ITC',
                'columns' => [['Details', 'left'], ['IGST', 'right'], ['CGST', 'right'], ['SGST', 'right'], ['Total', 'right']],
                'rows' => [['cells' => ['All other ITC (from Input GST ledgers)', $report['itc']['igst'], $report['itc']['cgst'], $report['itc']['sgst'], $report['itc']['total']]]],
            ],
            [
                'type' => 'table',
                'heading' => '6. Payment of tax',
                'columns' => [['Head', 'left'], ['Output tax', 'right'], ['ITC', 'right'], ['Net', 'right'], ['Payable', 'right'], ['Credit c/f', 'right']],
                'rows' => [
                    ...array_map(fn ($head) => ['cells' => [strtoupper($head), ...array_values($heads[$head])]], array_keys($heads)),
                    ['cells' => ['Total', ...array_values($report['totals'])], 'bold' => true],
                ],
            ],
        ], ['Figures come from the GST ledgers; verify against the GST portal before filing.']);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $notes
     */
    private function render(string $title, string $subtitle, string $store, array $blocks, array $notes = []): string
    {
        return Pdf::loadView('accounts.statement', compact('title', 'subtitle', 'store', 'blocks', 'notes'))
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('d M Y');
    }
}
