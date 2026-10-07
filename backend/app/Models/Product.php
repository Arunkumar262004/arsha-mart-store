<?php

namespace App\Models;

use App\Support\StoreContext;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock is kept per store in product_stocks. `$product->stock` reads the
 * current store's quantity (or the total when viewing all stores); queries
 * that list or filter by stock use the withStock() / belowStock() scopes.
 *
 * Setting `stock` when creating a product (factories, seeders) puts that
 * quantity straight into the current store without a movement; the API adds
 * opening stock through StockService instead so it is logged.
 */
#[Fillable(['name', 'code', 'hsn_code', 'category', 'unit', 'price', 'cost_price', 'tax_percent', 'stock'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    private ?int $pendingStock = null;

    protected static function booted(): void
    {
        static::saved(function (Product $product) {
            if ($product->pendingStock === null) {
                return;
            }

            ProductStock::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => app(StoreContext::class)->id()],
                ['stock' => $product->pendingStock],
            );
            $product->pendingStock = null;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
        ];
    }

    public function setStockAttribute(int|string|null $value): void
    {
        $this->pendingStock = (int) $value;
    }

    /**
     * The selected stock column when loaded through withStock(), otherwise
     * a fresh lookup for the current store (sum of all stores in "all" mode).
     */
    public function getStockAttribute(): int
    {
        if (array_key_exists('stock', $this->attributes)) {
            return (int) $this->attributes['stock'];
        }

        if (! $this->exists) {
            return $this->pendingStock ?? 0;
        }

        return (int) $this->stocks()
            ->when(app(StoreContext::class)->scopeId(), fn (Builder $q, int $id) => $q->where('store_id', $id))
            ->sum('stock');
    }

    /**
     * Stock at a specific store.
     */
    public function stockAt(Store|int $store): int
    {
        return (int) $this->stocks()->where('store_id', $store instanceof Store ? $store->id : $store)->value('stock');
    }

    /**
     * @return HasMany<ProductStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Subquery for the stock of each product at a store (or all stores).
     *
     * @return Builder<ProductStock>
     */
    public static function stockSubquery(?int $storeId): Builder
    {
        return ProductStock::query()
            ->selectRaw('COALESCE(SUM(stock), 0)')
            ->whereColumn('product_stocks.product_id', 'products.id')
            ->when($storeId, fn (Builder $q, int $id) => $q->where('store_id', $id));
    }

    /**
     * Add a `stock` column: the given store, else the current store (or the
     * total of every store in "all" mode, or when $allStores is true).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithStock(Builder $query, ?int $storeId = null, bool $allStores = false): void
    {
        $storeId = $allStores ? null : ($storeId ?? app(StoreContext::class)->scopeId());

        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        $query->selectSub(static::stockSubquery($storeId), 'stock');
    }

    /**
     * Products whose stock (current store) is strictly below the threshold.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeBelowStock(Builder $query, int $threshold): void
    {
        $query->where(static::stockSubquery(app(StoreContext::class)->scopeId()), '<', $threshold);
    }

    /**
     * Products with exactly this much stock (current store), e.g. 0 = out of stock.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeStockEquals(Builder $query, int $quantity): void
    {
        $query->where(static::stockSubquery(app(StoreContext::class)->scopeId()), '=', $quantity);
    }
}
