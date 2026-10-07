<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Product;
use App\Models\Store;
use App\Services\StockService;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    /**
     * Restock or correct a product's stock level.
     */
    public function adjust(AdjustStockRequest $request, Product $product, StockService $stock): JsonResponse
    {
        $movement = $stock->adjust(
            $product,
            $request->integer('quantity'),
            $request->validated('type'),
            $request->user(),
            $request->validated('note'),
        );

        return response()->json([
            'data' => ProductResource::make($product),
            'movement' => StockMovementResource::make($movement->load('user')),
        ]);
    }

    /**
     * Stock history for one product at the current store (every store in
     * "all stores" mode), newest first.
     */
    public function movements(Product $product, StoreContext $context): AnonymousResourceCollection
    {
        return StockMovementResource::collection(
            $product->stockMovements()
                ->when($context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
                ->with(['user', 'order', 'store'])
                ->latest('id')
                ->paginate(20)
        );
    }

    /**
     * How much of a product each store the user can see holds.
     */
    public function byStore(Request $request, Product $product): JsonResponse
    {
        $stocks = $product->stocks()->pluck('stock', 'store_id');

        $rows = $request->user()->accessibleStores()->map(fn (Store $store) => [
            'store_id' => $store->id,
            'store_name' => $store->name,
            'store_code' => $store->code,
            'stock' => (int) ($stocks[$store->id] ?? 0),
        ]);

        return response()->json(['data' => $rows, 'meta' => ['total' => $rows->sum('stock')]]);
    }
}
