<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseReturnRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Services\ReturnService;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseReturnController extends Controller
{
    /**
     * Purchase returns in the current store (or all). Filters: from, to, supplier_id, search.
     */
    public function index(Request $request, StoreContext $context): AnonymousResourceCollection
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $search = mb_strtolower(trim((string) $request->query('search')));

        $query = PurchaseReturn::query()
            ->when($context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('date', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('date', '<', $to->addDay()->toDateString()))
            ->when($request->integer('supplier_id'), fn ($q, int $id) => $q->where('supplier_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(number) LIKE ?', ["%{$search}%"])
                ->orWhereHas('supplier', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))));

        $total = (clone $query)->sum('grand_total');

        return PurchaseReturnResource::collection(
            $query->with(['supplier', 'store', 'purchase'])->latest('date')->latest('id')
                ->paginate(min(max($request->integer('per_page', 25), 1), 100))
        )->additional(['meta' => ['totals' => ['grand_total' => number_format((float) $total, 2, '.', '')]]]);
    }

    public function show(Request $request, PurchaseReturn $purchaseReturn): PurchaseReturnResource
    {
        abort_unless($request->user()->canAccessStore($purchaseReturn->store_id), 404);

        return PurchaseReturnResource::make($purchaseReturn->load(['supplier', 'store', 'purchase', 'items.product']));
    }

    public function store(StorePurchaseReturnRequest $request, ReturnService $service, StoreContext $context): JsonResponse
    {
        if ($request->filled('purchase_id')) {
            $purchase = Purchase::findOrFail($request->integer('purchase_id'));
            abort_unless($request->user()->canAccessStore($purchase->store_id), 404);
        }

        $return = $service->createPurchaseReturn($request->validated(), $context->store(), $request->user());

        return PurchaseReturnResource::make($return->load(['supplier', 'store', 'purchase', 'items.product']))
            ->response()->setStatusCode(201);
    }

    /**
     * A supplier's posted purchases (current store, or all) with the
     * quantities still returnable, for linking a return to a purchase.
     */
    public function purchases(Request $request, StoreContext $context, ReturnService $service): AnonymousResourceCollection
    {
        $request->validate(['supplier_id' => ['required', 'integer']]);

        $purchases = Purchase::query()
            ->where('supplier_id', $request->integer('supplier_id'))
            ->where('status', Purchase::STATUS_POSTED)
            ->when($context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
            ->with(['items.product', 'store'])
            ->latest('date')->latest('id')
            ->limit(50)
            ->get();

        foreach ($purchases as $purchase) {
            $returnable = $service->returnableForPurchase($purchase);
            foreach ($purchase->items as $item) {
                $item->setAttribute('returnable', max($returnable[$item->product_id] ?? 0, 0));
            }
        }

        return PurchaseResource::collection($purchases);
    }
}
