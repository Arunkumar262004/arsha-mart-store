<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Goods sent back to a supplier (debit note). Created through App\Services\ReturnService.
 */
#[Fillable([
    'store_id', 'number', 'supplier_id', 'purchase_id', 'date', 'reason', 'is_interstate',
    'subtotal', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_total', 'grand_total', 'refund_mode',
    'created_by', 'created_by_name',
])]
class PurchaseReturn extends Model
{
    public const REFUND_MODES = ['credit', 'cash', 'bank'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_interstate' => 'boolean',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return HasMany<PurchaseReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /**
     * @return MorphOne<Voucher, $this>
     */
    public function voucher(): MorphOne
    {
        return $this->morphOne(Voucher::class, 'source');
    }
}
