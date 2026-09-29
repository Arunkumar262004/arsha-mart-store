<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
    {
        $order = $orders->placeOrder(
            email: $request->validated('customer_email'),
            name: $request->validated('customer_name'),
            items: $request->validated('items'),
            amountPaid: $request->validated('amount_paid'),
            phone: $request->validated('customer_phone'),
            cashier: $request->user(),
            customerId: $request->validated('customer_id'),
            updateCustomer: $request->boolean('update_customer'),
            interstate: $request->boolean('interstate'),
        );

        return OrderResource::make($order)->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        return OrderResource::make($order->load(['customer', 'cashier', 'items.product']));
    }
}
