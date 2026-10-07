<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Goods received from a supplier into one store. Created and cancelled only
 * through App\Services\PurchaseService.
 */
#[Fillable([
    'store_id', 'number', 'supplier_id', 'supplier_invoice_number', 'supplier_invoice_date', 'date',
    'is_interstate', 'payment_mode', 'subtotal', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_total',
    'freight', 'round_off', 'grand_total', 'amount_paid', 'status', 'cancelled_at', 'notes',
    'created_by', 'created_by_name',
])]
class Purchase extends Model
{
    public const MODE_CASH = 'cash';

    public const MODE_BANK = 'bank';

    public const MODE_CREDIT = 'credit';

    public const PAYMENT_MODES = [self::MODE_CASH, self::MODE_BANK, self::MODE_CREDIT];

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_invoice_date' => 'date',
            'date' => 'date',
            'is_interstate' => 'boolean',
            'subtotal' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'freight' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
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
     * @return HasMany<PurchaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * @return HasMany<PurchaseReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * The purchase voucher, the payment voucher of a cash/bank purchase and,
     * once cancelled, the reversing journal.
     *
     * @return MorphMany<Voucher, $this>
     */
    public function vouchers(): MorphMany
    {
        return $this->morphMany(Voucher::class, 'source');
    }
}
