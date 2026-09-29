<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerLookupRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    /**
     * Find a customer by email or mobile number, so the billing screen can
     * auto-fill the remaining customer fields.
     */
    public function lookup(CustomerLookupRequest $request): CustomerResource
    {
        $customer = Customer::query()
            ->when(
                $request->filled('email'),
                fn ($q) => $q->where('email', $request->validated('email')),
                fn ($q) => $q->where('phone', $request->validated('phone')),
            )
            ->first();

        abort_if($customer === null, 404, 'No customer found.');

        return CustomerResource::make($customer);
    }

    /**
     * A customer's order history, newest first.
     */
    public function orders(Request $request, string $email): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $customer = Customer::where('email', mb_strtolower(trim($email)))->first();

        abort_if($customer === null, 404, 'No customer found with that email.');

        $orders = $customer->orders()
            ->with(['items.product', 'cashier'])
            ->latest()
            ->latest('id')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        return OrderResource::collection($orders)
            ->additional(['customer' => CustomerResource::make($customer)]);
    }
}
