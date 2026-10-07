<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMoneyTransactionRequest;
use App\Http\Resources\MoneyTransactionResource;
use App\Models\Account;
use App\Models\Customer;
use App\Models\MoneyTransaction;
use App\Models\VoucherEntry;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customer receipts (/api/receipts), supplier payments (/api/payments) and
 * expenses (/api/expenses). The URL decides which kind is listed or saved.
 */
class MoneyTransactionController extends Controller
{
    /**
     * Entries of this kind in the current store (or all), newest first.
     * Filters: from, to, mode, status, customer_id / supplier_id / account_id,
     * search (number, reference, party, paid to). meta.totals sums the
     * non-cancelled entries matching the filters.
     */
    public function index(Request $request, StoreContext $context): AnonymousResourceCollection
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $kind = self::kindOf($request);
        $search = mb_strtolower(trim((string) $request->query('search')));

        $query = MoneyTransaction::query()
            ->where('kind', $kind)
            ->when($context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('date', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('date', '<', $to->addDay()->toDateString()))
            ->when(in_array($request->query('mode'), MoneyTransaction::MODES, true), fn ($q) => $q->where('mode', $request->query('mode')))
            ->when(in_array($request->query('status'), [MoneyTransaction::STATUS_POSTED, MoneyTransaction::STATUS_CANCELLED], true),
                fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->integer('customer_id'), fn ($q, int $id) => $q->where('customer_id', $id))
            ->when($request->integer('supplier_id'), fn ($q, int $id) => $q->where('supplier_id', $id))
            ->when($request->integer('account_id'), fn ($q, int $id) => $q->where('account_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(number) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(reference) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(paid_to) LIKE ?', ["%{$search}%"])
                ->orWhereHas('customer', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))
                ->orWhereHas('supplier', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))));

        $totals = (clone $query)->where('status', MoneyTransaction::STATUS_POSTED)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(total), 0) as total')
            ->first();

        $entries = $query->with(['customer', 'supplier', 'account', 'store'])
            ->latest('date')->latest('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return MoneyTransactionResource::collection($entries)->additional(['meta' => ['totals' => [
            'count' => (int) $totals->count,
            'amount' => number_format((float) $totals->amount, 2, '.', ''),
            'total' => number_format((float) $totals->total, 2, '.', ''),
        ]]]);
    }

    public function store(StoreMoneyTransactionRequest $request, PaymentService $service, StoreContext $context): JsonResponse
    {
        $data = $request->validated();
        $store = $context->store();

        $entry = match ($request->kind()) {
            MoneyTransaction::RECEIPT => $service->receipt($data, $store, $request->user()),
            MoneyTransaction::PAYMENT => $service->payment($data, $store, $request->user()),
            default => $service->expense($data, $store, $request->user()),
        };

        return MoneyTransactionResource::make($entry->load(['customer', 'supplier', 'account', 'store']))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, MoneyTransaction $transaction): MoneyTransactionResource
    {
        $this->authorizeEntry($request, $transaction);

        return MoneyTransactionResource::make($transaction->load(['customer', 'supplier', 'account', 'store']));
    }

    /**
     * Cancel the entry: it stays listed as cancelled and a journal reverses its voucher.
     */
    public function cancel(Request $request, MoneyTransaction $transaction, PaymentService $service): MoneyTransactionResource
    {
        $this->authorizeEntry($request, $transaction);

        $transaction = $service->cancel($transaction, $request->user());

        return MoneyTransactionResource::make($transaction->load(['customer', 'supplier', 'account', 'store']));
    }

    /**
     * Customers who owe us money (receivable balance above zero, all stores),
     * for the receipt form's picker. Optional search by name, email or phone.
     */
    public function customersWithOutstanding(Request $request): JsonResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search')));

        $ledgers = Account::query()
            ->where('party_type', (new Customer)->getMorphClass())
            ->with('party')
            ->get();

        $totals = VoucherEntry::query()
            ->whereIn('account_id', $ledgers->pluck('id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->get()
            ->keyBy('account_id');

        $rows = $ledgers->map(function (Account $ledger) use ($totals) {
            $row = $totals->get($ledger->id);
            $balance = Money::toCents($ledger->opening_balance)
                + ($row ? Money::toCents($row->debit) - Money::toCents($row->credit) : 0);

            return [$ledger->party, $balance];
        })
            ->filter(fn (array $pair) => $pair[0] !== null && $pair[1] > 0)
            ->filter(fn (array $pair) => $search === ''
                || str_contains(mb_strtolower($pair[0]->name.' '.$pair[0]->email.' '.$pair[0]->phone), $search))
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'name' => $pair[0]->name,
                'email' => $pair[0]->email,
                'phone' => $pair[0]->phone,
                'outstanding' => Money::format($pair[1]),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Active expense ledgers (direct and indirect) for the expense form.
     */
    public function expenseAccounts(): JsonResponse
    {
        $accounts = Account::active()
            ->whereIn('group', ['indirect_expense', 'direct_expense'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'group']);

        return response()->json(['data' => $accounts]);
    }

    private function authorizeEntry(Request $request, MoneyTransaction $transaction): void
    {
        abort_unless(
            $transaction->kind === self::kindOf($request) && $request->user()->canAccessStore($transaction->store_id),
            404,
        );
    }

    private static function kindOf(Request $request): string
    {
        return match (true) {
            $request->is('api/receipts*') => MoneyTransaction::RECEIPT,
            $request->is('api/payments*') => MoneyTransaction::PAYMENT,
            default => MoneyTransaction::EXPENSE,
        };
    }
}
