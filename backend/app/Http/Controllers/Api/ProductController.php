<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LowStockRequest;
use App\Http\Requests\SaveProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductResource::collection(Product::orderBy('name')->get());
    }

    public function lowStock(LowStockRequest $request): AnonymousResourceCollection
    {
        $threshold = $request->threshold();

        return ProductResource::collection(
            Product::belowStock($threshold)->orderBy('stock')->orderBy('name')->get()
        )->additional(['meta' => ['threshold' => $threshold]]);
    }

    /**
     * Add a product; its opening stock is logged as the first movement.
     */
    public function store(SaveProductRequest $request, StockService $stock): JsonResponse
    {
        $product = DB::transaction(function () use ($request, $stock) {
            $product = Product::create([...$request->safe()->except('stock'), 'stock' => 0]);

            if ($request->integer('stock') > 0) {
                $stock->adjust($product, $request->integer('stock'), StockMovement::TYPE_INITIAL, $request->user(), 'Opening stock');
            }

            return $product->refresh();
        });

        return ProductResource::make($product)->response()->setStatusCode(201);
    }

    /**
     * Edit catalog details (not stock). Past bills keep the price they
     * were sold at because order lines store their own copy.
     */
    public function update(SaveProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return ProductResource::make($product);
    }
}
