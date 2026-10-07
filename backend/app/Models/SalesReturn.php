<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Goods a customer brought back against a bill (credit note). Created
 * through App\Services\ReturnService; priced from the original bill lines.
 */
#[Fillable([
    'store_id', 'number', 'order_id', 'customer_id', 'date', 'reason',
    'subtotal', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_total', 'grand_total', 'refund_mode',
    'created_by', 'created_by_name',
])]
class SalesReturn extends Model
{
    public const REFUND_MODES = ['cash', 'bank', 'credit'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'subtotal' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<SalesReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    /**
     * @return MorphOne<Voucher, $this>
     */
    public function voucher(): MorphOne
    {
        return $this->morphOne(Voucher::class, 'source');
    }
}
