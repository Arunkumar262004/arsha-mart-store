<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
    {
        $order = $orders->placeOrder(
            email: $request->validated('customer_email'),
            name: $request->validated('customer_name'),
            // Only product and quantity: a bill always charges the product's price.
            items: array_map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ], $request->validated('items')),
            amountPaid: $request->validated('amount_paid'),
            phone: $request->validated('customer_phone'),
            cashier: $request->user(),
            customerId: $request->validated('customer_id'),
            updateCustomer: $request->boolean('update_customer'),
            interstate: $request->boolean('interstate'),
            paymentMode: $request->validated('payment_mode') ?? Order::PAYMENT_CASH,
            customerGstin: $request->validated('customer_gstin'),
            billingAddress: $request->validated('billing_address'),
            placeOfSupply: $request->validated('place_of_supply'),
        );

        return OrderResource::make($order)->response()->setStatusCode(201);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless($order->store_id === null || $request->user()->canAccessStore($order->store_id), 404);

        return OrderResource::make($order->load(['customer', 'cashier', 'items.product', 'store']));
    }
}
