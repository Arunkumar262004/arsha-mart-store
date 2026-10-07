<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['product_id', 'store_id', 'user_id', 'user_name', 'order_id', 'source_type', 'source_id', 'type', 'quantity', 'stock_after', 'note'])]
class StockMovement extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_RESTOCK = 'restock';

    public const TYPE_CORRECTION = 'correction';

    public const TYPE_INITIAL = 'initial';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_PURCHASE_RETURN = 'purchase_return';

    public const TYPE_SALE_RETURN = 'sale_return';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_CHALLAN = 'challan';

    public const TYPE_WRITE_OFF = 'write_off';

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The document behind the change (purchase, transfer, challan, ...).
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
