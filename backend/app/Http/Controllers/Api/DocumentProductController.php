<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The product list (with the current store's stock) for the quotation,
 * challan and transfer forms, for staff who may make those documents but
 * neither view inventory nor bill.
 */
class DocumentProductController extends Controller
{
    private const ALLOWED = ['quotations.manage', 'challans.manage', 'transfers.manage', 'products.view', 'billing.create'];

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless(collect(self::ALLOWED)->contains(fn (string $key) => $user->hasPermission($key)), 403);

        return ProductResource::collection(Product::withStock()->orderBy('name')->get());
    }
}
