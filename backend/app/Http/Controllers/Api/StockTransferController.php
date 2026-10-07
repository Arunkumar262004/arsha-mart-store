<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveStockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\StockTransferService;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * Transfers in or out of the current store (every transfer in all-stores
     * mode). direction=outgoing|incoming narrows it to one side.
     */
    public function index(Request $request, StoreContext $context): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['outgoing', 'incoming'])],
            'status' => ['nullable', Rule::in(StockTransfer::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $storeId = $context->scopeId();
        $direction = $filters['direction'] ?? null;
        $search = mb_strtolower(trim($filters['search'] ?? ''));

        $transfers = StockTransfer::query()
            ->when($storeId !== null, fn (Builder $q) => match ($direction) {
                'outgoing' => $q->where('from_store_id', $storeId),
                'incoming' => $q->where('to_store_id', $storeId),
                default => $q->where(fn (Builder $q) => $q->where('from_store_id', $storeId)->orWhere('to_store_id', $storeId)),
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->whereRaw('LOWER(number) LIKE ?', ["%{$search}%"]))
            ->with(['fromStore', 'toStore', 'items.product'])
            ->latest('dispatched_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return StockTransferResource::collection($transfers);
    }

    /**
     * Active stores stock can be sent to from the current store. Includes
     * stores the user doesn't work in: staff of one store send to the others.
     */
    public function destinations(StoreContext $context): JsonResponse
    {
        $stores = Store::query()->active()->whereKeyNot($context->id())->orderBy('name')->get(['id', 'name', 'code', 'city']);

        return response()->json(['data' => $stores]);
    }

    public function show(Request $request, StockTransfer $transfer): StockTransferResource
    {
        $user = $request->user();
        abort_unless($user->canAccessStore($transfer->from_store_id) || $user->canAccessStore($transfer->to_store_id), 404);

        return StockTransferResource::make($transfer->load(['fromStore', 'toStore', 'items.product']));
    }

    /**
     * Dispatch from the current store.
     */
    public function store(SaveStockTransferRequest $request, StoreContext $context): JsonResponse
    {
        $transfer = $this->transfers->dispatch($request->validated(), $context->store(), $request->user());

        return StockTransferResource::make($transfer)->response()->setStatusCode(201);
    }

    /**
     * Only someone who works at the destination store can receive.
     */
    public function receive(Request $request, StockTransfer $transfer): StockTransferResource
    {
        abort_unless($request->user()->canAccessStore($transfer->to_store_id), 403, 'Only the receiving store can receive this transfer.');

        return StockTransferResource::make($this->transfers->receive($transfer, $request->user()));
    }

    /**
     * Only the sending store can call a transfer off while it is in transit.
     */
    public function cancel(Request $request, StockTransfer $transfer): StockTransferResource
    {
        abort_unless($request->user()->canAccessStore($transfer->from_store_id), 403, 'Only the sending store can cancel this transfer.');

        return StockTransferResource::make($this->transfers->cancel($transfer, $request->user()));
    }
}
