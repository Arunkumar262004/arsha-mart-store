<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header bell: live low-stock alerts derived from current data rather
 * than a stored inbox, so they clear themselves once a product is restocked.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = collect();

        if ($request->user()->can('products.view')) {
            $threshold = (int) config('inventory.low_stock_threshold');

            foreach (Product::belowStock($threshold)->orderBy('stock')->orderBy('name')->get() as $product) {
                $out = $product->stock === 0;
                $items->push([
                    'id' => "stock-{$product->id}-{$product->stock}",
                    'type' => $out ? 'out_of_stock' : 'low_stock',
                    'severity' => $out ? 'danger' : 'warning',
                    'title' => $product->name,
                    'message' => $out ? 'Out of stock' : "Only {$product->stock} left (below {$threshold})",
                    'link' => '/inventory?filter=low',
                    'at' => $product->updated_at?->toIso8601String(),
                ]);
            }
        }

        return response()->json(['data' => $items->values(), 'meta' => ['count' => $items->count()]]);
    }
}
