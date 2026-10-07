<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Resources\SalesReturnResource;
use App\Http\Resources\StoreResource;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Services\ReturnService;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesReturnController extends Controller
{
    /**
     * Sales returns in the current store (or all). Filters: from, to, search
     * (return number, invoice number, customer name).
     */
    public function index(Request $request, StoreContext $context): AnonymousResourceCollection
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $search = mb_strtolower(trim((string) $request->query('search')));

        $query = SalesReturn::query()
            ->when($context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('date', '>=', $from->toDateString()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('date', '<', $to->addDay()->toDateString()))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(number) LIKE ?', ["%{$search}%"])
                ->orWhereHas('order', fn ($q) => $q->whereRaw('LOWER(invoice_number) LIKE ?', ["%{$search}%"]))
                ->orWhereHas('customer', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))));

        $total = (clone $query)->sum('grand_total');

        return SalesReturnResource::collection(
            $query->with(['order', 'customer', 'store'])->latest('date')->latest('id')
                ->paginate(min(max($request->integer('per_page', 25), 1), 100))
        )->additional(['meta' => ['totals' => ['grand_total' => number_format((float) $total, 2, '.', '')]]]);
    }

    public function show(Request $request, SalesReturn $salesReturn): SalesReturnResource
    {
        abort_unless($request->user()->canAccessStore($salesReturn->store_id), 404);

        return SalesReturnResource::make($salesReturn->load(['order', 'customer', 'store', 'items.product']));
    }

    public function store(StoreSalesReturnRequest $request, ReturnService $service): JsonResponse
    {
        $order = Order::findOrFail($request->integer('order_id'));
        abort_unless($request->user()->canAccessStore($order->store_id), 404);

        $return = $service->createSalesReturn($request->validated(), $request->user());

        return SalesReturnResource::make($return->load(['order', 'customer', 'store', 'items.product']))
            ->response()->setStatusCode(201);
    }

    /**
     * Find a bill by invoice number or order number (in the current store,
     * or any accessible store in "all" mode) with what can still be returned.
     */
    public function lookup(Request $request, StoreContext $context, ReturnService $service): JsonResponse
    {
        $request->validate(['number' => ['required', 'string', 'max:60']]);
        $number = mb_strtoupper(trim($request->query('number')));

        $order = Order::query()
            ->where(fn ($q) => $q->where('invoice_number', $number)->orWhere('order_number', $number))
            ->when($context->scopeId(), fn ($q, int $id) => $q->where('store_id', $id))
            ->with(['items.product', 'customer', 'store'])
            ->first();

        if (! $order || ! $request->user()->canAccessStore($order->store_id)) {
            return response()->json(['message' => 'No bill with this number in this store.'], 404);
        }

        $returned = $service->returnedForOrder($order);

        return response()->json(['data' => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'invoice_number' => $order->invoice_number,
            'payment_mode' => $order->payment_mode,
            'is_interstate' => $order->is_interstate,
            'grand_total' => $order->grand_total,
            'created_at' => $order->created_at?->toIso8601String(),
            'store' => StoreResource::make($order->store),
            'customer' => $order->customer?->only(['id', 'name', 'email', 'phone']),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product?->only(['id', 'name', 'code', 'unit']),
                'unit_price' => $item->unit_price,
                'tax_percent' => $item->tax_percent,
                'cgst_percent' => $item->cgst_percent,
                'sgst_percent' => $item->sgst_percent,
                'igst_percent' => $item->igst_percent,
                'quantity' => $item->quantity,
                'returned' => $returned[$item->id]['quantity'] ?? 0,
                'returnable' => $item->quantity - ($returned[$item->id]['quantity'] ?? 0),
                'line_total' => $item->line_total,
            ])->values(),
        ]]);
    }
}
