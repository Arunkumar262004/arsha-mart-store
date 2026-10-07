<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price offer to a customer. Prices are fixed on the quotation and kept
 * when it is converted into a bill.
 */
#[Fillable([
    'store_id', 'number', 'customer_id', 'customer_name', 'customer_email', 'customer_phone', 'customer_gstin',
    'date', 'valid_until', 'status', 'is_interstate', 'subtotal', 'tax_total', 'cgst_amount', 'sgst_amount',
    'igst_amount', 'grand_total', 'notes', 'converted_order_id', 'created_by', 'created_by_name',
])]
class Quotation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CONVERTED = 'converted';

    /** Statuses a user can set by hand (converted comes from converting). */
    public const MANUAL_STATUSES = [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_CANCELLED];

    /** Derived, never stored: past valid_until and still open. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'valid_until' => 'date',
            'is_interstate' => 'boolean',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    /** Converted or cancelled quotations can no longer change. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CONVERTED, self::STATUS_CANCELLED], true);
    }

    public function isExpired(): bool
    {
        return ! $this->isClosed() && $this->valid_until !== null && $this->valid_until->lt(today());
    }

    /** The status to show: "expired" for an open quotation past its validity. */
    public function displayStatus(): string
    {
        return $this->isExpired() ? self::STATUS_EXPIRED : $this->status;
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
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
    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }
}
