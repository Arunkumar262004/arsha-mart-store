<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock moving from one store to another. Dispatch takes it out of the
 * source store; it lands at the destination only when received there.
 */
#[Fillable([
    'number', 'from_store_id', 'to_store_id', 'status', 'dispatched_at', 'received_at',
    'dispatched_by', 'dispatched_by_name', 'received_by', 'received_by_name', 'vehicle_number', 'notes',
])]
class StockTransfer extends Model
{
    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_IN_TRANSIT, self::STATUS_RECEIVED, self::STATUS_CANCELLED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }
}
