<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way stock changes. Every change locks the product row (the same
 * lock bill placement takes) and the store's stock row, so concurrent sales,
 * restocks, purchases and transfers never lose an update, and each change is
 * written to the stock_movements log with the resulting level.
 */
class StockService
{
    public function __construct(private readonly StoreContext $context) {}

    /**
     * Change one product's stock at a store (default: the current store) by a
     * signed quantity and record why.
     *
     * @throws ValidationException when the change would make stock negative
     */
    public function adjust(
        Product $product,
        int $quantity,
        string $type,
        ?User $user,
        ?string $note = null,
        ?Store $store = null,
        ?Model $source = null,
    ): StockMovement {
        $store ??= $this->context->store();

        return DB::transaction(function () use ($product, $quantity, $type, $user, $note, $store, $source) {
            $locked = $this->lock($store, [$product->id])->first();

            if ($locked->stock + $quantity < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Cannot remove '.abs($quantity)." unit(s); only {$locked->stock} in stock.",
                ]);
            }

            $movement = $this->apply($locked, $product, $quantity, $type, $user, $note, $source);
            // Raw and synced, so a later save of the product never writes it.
            $product->setRawAttributes(['stock' => $locked->stock] + $product->getAttributes(), true);

            return $movement;
        });
    }

    /**
     * Apply several signed changes at one store in a single transaction, for
     * documents with many lines (purchases, transfers, challans, returns).
     * All lines are checked first; if any would go negative nothing changes.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $lines  keys are kept as line indexes in errors
     * @return list<StockMovement>
     *
     * @throws InsufficientStockException
     */
    public function moveMany(
        Store $store,
        array $lines,
        string $type,
        ?User $user,
        ?string $note = null,
        ?Model $source = null,
        ?Order $order = null,
    ): array {
        return DB::transaction(function () use ($store, $lines, $type, $user, $note, $source, $order) {
            $stocks = $this->lock($store, array_column($lines, 'product_id'));
            $products = Product::query()->whereKey(array_column($lines, 'product_id'))->get()->keyBy('id');

            $shortages = [];
            foreach ($lines as $index => $line) {
                $available = $stocks[(int) $line['product_id']]->stock;
                if ((int) $line['quantity'] < 0 && $available + (int) $line['quantity'] < 0) {
                    $shortages[] = [
                        'index' => $index,
                        'product_id' => (int) $line['product_id'],
                        'name' => $products[(int) $line['product_id']]?->name ?? 'Unknown product',
                        'requested' => -(int) $line['quantity'],
                        'available' => $available,
                    ];
                }
            }

            if ($shortages !== []) {
                throw new InsufficientStockException($shortages);
            }

            $movements = [];
            foreach ($lines as $line) {
                $movements[] = $this->apply(
                    $stocks[(int) $line['product_id']],
                    $products[(int) $line['product_id']],
                    (int) $line['quantity'],
                    $type, $user, $note, $source, $order,
                );
            }

            return $movements;
        });
    }

    /**
     * Lock the products and their stock rows at a store, creating missing
     * stock rows at zero. Products are locked in id order so two callers
     * touching the same products can never deadlock. Must run inside a
     * transaction.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, ProductStock> keyed by product id
     */
    public function lock(Store $store, array $productIds): Collection
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        sort($productIds);

        // The product row lock is the one bill placement has always taken.
        Product::query()->whereKey($productIds)->orderBy('id')->lockForUpdate()->pluck('id');

        ProductStock::query()->insertOrIgnore(array_map(fn (int $id) => [
            'product_id' => $id, 'store_id' => $store->id, 'stock' => 0,
        ], $productIds));

        return ProductStock::query()
            ->where('store_id', $store->id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
    }

    /**
     * Update a locked stock row and log the movement.
     */
    private function apply(
        ProductStock $stock,
        Product $product,
        int $quantity,
        string $type,
        ?User $user,
        ?string $note,
        ?Model $source = null,
        ?Order $order = null,
    ): StockMovement {
        $stock->forceFill(['stock' => $stock->stock + $quantity])->save();

        return $product->stockMovements()->create([
            'store_id' => $stock->store_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'order_id' => $order?->id,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'type' => $type,
            'quantity' => $quantity,
            'stock_after' => $stock->stock,
            'note' => $note,
        ]);
    }
}
