<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreController extends Controller
{
    /**
     * Active stores the signed-in user may switch to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StoreResource::collection($request->user()->accessibleStores());
    }

    /**
     * Every store including inactive ones, for the settings screen.
     */
    public function all(): AnonymousResourceCollection
    {
        return StoreResource::collection(Store::withCount('users')->orderBy('id')->get());
    }

    public function store(SaveStoreRequest $request): JsonResponse
    {
        return StoreResource::make(Store::create($request->validated()))->response()->setStatusCode(201);
    }

    public function update(SaveStoreRequest $request, Store $store): StoreResource
    {
        $store->update($request->validated());

        return StoreResource::make($store);
    }

    /**
     * Only a store with no history can be deleted; otherwise deactivate it.
     */
    public function destroy(Store $store): JsonResponse
    {
        abort_if($store->is(Store::main()), 422, 'The main store cannot be deleted.');
        abort_if(
            $store->orders()->exists() || $store->stocks()->where('stock', '>', 0)->exists()
                || StockMovement::where('store_id', $store->id)->exists()
                || Voucher::where('store_id', $store->id)->exists(),
            422,
            'This store has bills, stock or accounts history. Deactivate it instead.',
        );

        $store->delete();

        return response()->json(['message' => 'Store deleted.']);
    }
}
