<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\VoucherEntry;
use App\Services\LedgerService;
use App\Services\OutstandingService;
use App\Support\Money;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Account statements (ledger with running balance; the cash book and bank
 * book are the ledgers of 1000 / 1010) and party outstanding with ageing.
 */
class LedgerController extends Controller
{
    /** A statement longer than this is cut short (totals stay exact). */
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly StoreContext $context,
    ) {}

    public function show(Request $request, Account $account): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $storeId = $this->context->scopeId();
        $from = Carbon::parse($filters['from'] ?? LedgerService::financialYearStart());
        $to = Carbon::parse($filters['to'] ?? now()->toDateString());

        $opening = $this->ledger->openingOn($account, $storeId, $from);
        $period = $this->ledger->totals($storeId, $from, $to, [$account->id])[$account->id] ?? ['debit' => 0, 'credit' => 0];

        $entries = LedgerService::whereDateBetween(VoucherEntry::query(), 'date', $from, $to)
            ->where('account_id', $account->id)
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->with(['voucher.entries.account:id,code,name', 'voucher.store:id,code'])
            ->orderBy('date')->orderBy('id')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        $truncated = $entries->count() > self::MAX_ROWS;
        $running = $opening;
        $rows = $entries->take(self::MAX_ROWS)->map(function (VoucherEntry $entry) use (&$running, $account) {
            $debit = Money::toCents($entry->debit);
            $credit = Money::toCents($entry->credit);
            $running += $debit - $credit;
            $voucher = $entry->voucher;

            return [
                'id' => $entry->id,
                'date' => $entry->date->toDateString(),
                'voucher_id' => $voucher->id,
                'number' => $voucher->number,
                'type' => $voucher->type,
                'narration' => $voucher->narration,
                'store' => $voucher->store?->code,
                // The other side of the voucher, Tally's "Particulars".
                'particulars' => $voucher->entries->where('account_id', '!=', $account->id)->map(fn ($e) => $e->account?->name)->filter()->unique()->values()->implode(', '),
                'debit' => Money::format($debit),
                'credit' => Money::format($credit),
                'balance' => Money::format(abs($running)),
                'side' => LedgerService::side($running),
            ];
        })->values();

        $closing = $opening + $period['debit'] - $period['credit'];

        return response()->json([
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'group' => $account->group,
                'is_party' => $account->party_type !== null,
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'opening' => ['amount' => Money::format(abs($opening)), 'side' => LedgerService::side($opening)],
            'data' => $rows,
            'totals' => ['debit' => Money::format($period['debit']), 'credit' => Money::format($period['credit'])],
            'closing' => ['amount' => Money::format(abs($closing)), 'side' => LedgerService::side($closing)],
            'truncated' => $truncated,
            'all_stores' => $storeId === null,
        ]);
    }

    public function outstanding(Request $request, OutstandingService $outstanding): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['receivable', 'payable'])],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $type = $filters['type'] ?? 'receivable';
        $asOf = Carbon::parse($filters['as_of'] ?? now()->toDateString());

        $report = $outstanding->report($this->context->scopeId(), $type, $asOf, $filters['search'] ?? null);

        return response()->json([
            'type' => $type,
            'as_of' => $asOf->toDateString(),
            'data' => $report['rows'],
            'totals' => $report['totals'],
            'count' => $report['count'],
        ]);
    }
}
