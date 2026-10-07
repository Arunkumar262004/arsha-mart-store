<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods sent out before (or without) a sale: on approval, for job work, or
 * delivered now and invoiced later. Issuing takes the stock out of the store;
 * no accounting entry is made until the challan is invoiced.
 */
#[Fillable([
    'store_id', 'number', 'customer_id', 'customer_name', 'customer_email', 'customer_phone', 'customer_gstin',
    'delivery_address', 'date', 'purpose', 'status', 'vehicle_number', 'transporter', 'notes', 'order_id',
    'created_by', 'created_by_name',
])]
class DeliveryChallan extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_ISSUED, self::STATUS_INVOICED, self::STATUS_RETURNED, self::STATUS_CANCELLED];

    public const PURPOSES = ['sale', 'approval', 'job_work', 'other'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * @return HasMany<DeliveryChallanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryChallanItem::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
