<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
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
     * Stock history for one product, newest first.
     */
    public function movements(Product $product): AnonymousResourceCollection
    {
        return StockMovementResource::collection(
            $product->stockMovements()->with(['user', 'order'])->latest('id')->paginate(20)
        );
    }
}
