<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseController extends Controller
{
    /**
     * Purchases in the current store (or all), newest first. Filters: from,
     * to (dates), supplier_id, status, search (number, supplier bill no., supplier name).
     * meta.totals sums the posted purchases matching the filters.
     */
    public function index(Request $request, StoreContext $context): AnonymousResourceCollection
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $query = self::filtered(Purchase::query(), $request, $context);

        $totals = (clone $query)->where('status', Purchase::STATUS_POSTED)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(grand_total), 0) as grand_total, COALESCE(SUM(tax_total), 0) as tax_total')
            ->first();

        $purchases = $query->with(['supplier', 'store'])->withCount(['items', 'returns'])
            ->latest('date')->latest('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return PurchaseResource::collection($purchases)->additional(['meta' => ['totals' => [
            'count' => (int) $totals->count,
            'grand_total' => number_format((float) $totals->grand_total, 2, '.', ''),
            'tax_total' => number_format((float) $totals->tax_total, 2, '.', ''),
        ]]]);
    }

    public function show(Request $request, Purchase $purchase, ReturnService $returns): PurchaseResource
    {
        abort_unless($request->user()->canAccessStore($purchase->store_id), 404);

        $purchase->load(['supplier', 'store', 'items.product'])->loadCount('returns');

        $returnable = $returns->returnableForPurchase($purchase);
        foreach ($purchase->items as $item) {
            // Shared across lines of the same product.
            $item->setAttribute('returnable', $purchase->isCancelled() ? 0 : max($returnable[$item->product_id] ?? 0, 0));
        }

        return PurchaseResource::make($purchase);
    }

    public function store(StorePurchaseRequest $request, PurchaseService $service, StoreContext $context): JsonResponse
    {
        $purchase = $service->create($request->validated(), $context->store(), $request->user());

        return PurchaseResource::make($purchase->load(['supplier', 'store', 'items.product']))
            ->response()->setStatusCode(201);
    }

    /**
     * Cancel a purchase: stock out and reversing journal.
     */
    public function cancel(Request $request, Purchase $purchase, PurchaseService $service): PurchaseResource
    {
        abort_unless($request->user()->canAccessStore($purchase->store_id), 404);

        $purchase = $service->cancel($purchase, $request->user());

        return PurchaseResource::make($purchase->load(['supplier', 'store', 'items.product']));
    }

    /**
     * Product picker for purchase and return forms: catalog with cost,
     * GST rate and current-store stock. Available without products.view.
     */
    public function products(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(['purchases.manage', 'returns.manage']), 403);

        $products = Product::withStock()->orderBy('name')->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'hsn_code' => $p->hsn_code,
            'unit' => $p->unit,
            'price' => $p->price,
            'cost_price' => $p->cost_price,
            'tax_percent' => $p->tax_percent,
            'stock' => $p->stock,
        ]);

        return response()->json(['data' => $products]);
    }

    /**
     * @param  Builder<Purchase>  $query
     * @return Builder<Purchase>
     */
    private static function filtered(Builder $query, Request $request, StoreContext $context): Builder
    {
        $search = mb_strtolower(trim((string) $request->query('search')));

        return $query
            ->when($context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('date', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('date', '<', $to->addDay()->toDateString()))
            ->when($request->integer('supplier_id'), fn ($q, int $id) => $q->where('supplier_id', $id))
            ->when(in_array($request->query('status'), [Purchase::STATUS_POSTED, Purchase::STATUS_CANCELLED], true),
                fn ($q) => $q->where('status', $request->query('status')))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(number) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(supplier_invoice_number) LIKE ?', ["%{$search}%"])
                ->orWhereHas('supplier', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))));
    }
}
