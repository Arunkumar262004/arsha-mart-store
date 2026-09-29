<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    /**
     * Change a product's stock by a signed quantity and record why.
     *
     * Uses the same row lock as order placement, so a restock or correction
     * can never interleave with a sale and lose an update.
     *
     * @throws ValidationException when the change would make stock negative
     */
    public function adjust(Product $product, int $quantity, string $type, ?User $user, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($product, $quantity, $type, $user, $note) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->stock + $quantity < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Cannot remove ".abs($quantity)." unit(s); only {$locked->stock} in stock.",
                ]);
            }

            $locked->forceFill(['stock' => $locked->stock + $quantity])->save();
            $product->setRawAttributes($locked->getAttributes(), true);

            return $locked->stockMovements()->create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'type' => $type,
                'quantity' => $quantity,
                'stock_after' => $locked->stock,
                'note' => $note,
            ]);
        });
    }
}
