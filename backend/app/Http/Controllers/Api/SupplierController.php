<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSupplierRequest;
use App\Http\Resources\MoneyTransactionResource;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SupplierResource;
use App\Models\Account;
use App\Models\MoneyTransaction;
use App\Models\Supplier;
use App\Models\VoucherEntry;
use App\Support\Money;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    /** Anyone who works with suppliers may read the list (pickers on several screens). */
    private const READERS = ['suppliers.manage', 'purchases.manage', 'returns.manage', 'payments.manage'];

    /**
     * Suppliers with what we currently owe each (all stores). Filters:
     * search (name, GSTIN, phone), active=1 for active only.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAny(self::READERS), 403);

        $search = mb_strtolower(trim((string) $request->query('search')));

        $suppliers = Supplier::query()
            ->when($request->boolean('active'), fn ($q) => $q->active())
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(gstin) LIKE ?', ["%{$search}%"])
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();

        $this->attachBalances($suppliers);

        return SupplierResource::collection($suppliers);
    }

    /**
     * One supplier with balance and recent purchases / payments (current store, or all).
     */
    public function show(Supplier $supplier, StoreContext $context): JsonResponse
    {
        $this->attachBalances(collect([$supplier]));
        $storeId = $context->scopeId();

        $purchases = $supplier->purchases()
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->latest('date')->latest('id')->limit(10)->get();

        $payments = MoneyTransaction::query()
            ->where('kind', MoneyTransaction::PAYMENT)
            ->where('supplier_id', $supplier->id)
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            ->latest('date')->latest('id')->limit(10)->get();

        return SupplierResource::make($supplier)->additional([
            'recent_purchases' => PurchaseResource::collection($purchases),
            'recent_payments' => MoneyTransactionResource::collection($payments),
        ])->response();
    }

    /**
     * Add a supplier. An opening balance (amount we already owe them)
     * becomes the credit opening balance of their ledger.
     */
    public function store(SaveSupplierRequest $request): JsonResponse
    {
        $supplier = DB::transaction(function () use ($request) {
            $supplier = Supplier::create($request->safe()->except('opening_balance'));
            $this->saveOpeningBalance($supplier, $request->input('opening_balance'));

            return $supplier;
        });

        $this->attachBalances(collect([$supplier]));

        return SupplierResource::make($supplier)->response()->setStatusCode(201);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        DB::transaction(function () use ($request, $supplier) {
            $supplier->update($request->safe()->except('opening_balance'));
            // Keep the ledger name in step with the supplier's name.
            $supplier->ledger()->update(['name' => $supplier->name]);

            if ($request->has('opening_balance')) {
                $this->saveOpeningBalance($supplier, $request->input('opening_balance'));
            }
        });

        $this->attachBalances(collect([$supplier]));

        return SupplierResource::make($supplier);
    }

    /**
     * Only a supplier with no purchases, returns or payments can be deleted;
     * otherwise deactivate it so its history stays intact.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        abort_if($supplier->hasDocuments(), 422, 'This supplier has purchases or payments. Deactivate it instead.');

        DB::transaction(function () use ($supplier) {
            $supplier->ledger()->delete();
            $supplier->delete();
        });

        return response()->json(['message' => 'Supplier deleted.']);
    }

    private function saveOpeningBalance(Supplier $supplier, mixed $amount): void
    {
        $cents = Money::toCents($amount ?? 0);
        $ledger = $supplier->ledger()->first();

        if ($cents === 0 && ! $ledger) {
            return;
        }

        // Owed to the supplier = a credit balance, stored negative.
        ($ledger ?? Account::forParty($supplier))->update(['opening_balance' => Money::format(-$cents)]);
    }

    /**
     * Set `outstanding` (what we owe, positive) and `opening_balance` on each
     * supplier from their ledgers, with one query for all of them.
     *
     * @param  Collection<int, Supplier>  $suppliers
     */
    private function attachBalances(Collection $suppliers): void
    {
        $ledgers = Account::query()
            ->where('party_type', (new Supplier)->getMorphClass())
            ->whereIn('party_id', $suppliers->pluck('id'))
            ->get()
            ->keyBy('party_id');

        $totals = VoucherEntry::query()
            ->whereIn('account_id', $ledgers->pluck('id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->get()
            ->keyBy('account_id');

        foreach ($suppliers as $supplier) {
            $ledger = $ledgers->get($supplier->id);
            $opening = $ledger ? Money::toCents($ledger->opening_balance) : 0;
            $row = $ledger ? $totals->get($ledger->id) : null;
            $balance = $opening + ($row ? Money::toCents($row->debit) - Money::toCents($row->credit) : 0);

            $supplier->setAttribute('outstanding', Money::format(-$balance));
            $supplier->setAttribute('opening_balance', Money::format(-$opening));
        }
    }
}
