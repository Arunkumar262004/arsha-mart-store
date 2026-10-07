<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable([
    'store_id', 'order_number', 'invoice_number', 'payment_mode', 'customer_id', 'subtotal', 'tax_total', 'is_interstate',
    'cgst_amount', 'sgst_amount', 'igst_amount', 'grand_total',
    'amount_paid', 'change_due', 'confirmation_sent_at', 'whatsapp_sent_at', 'created_by', 'created_by_name',
    'customer_gstin', 'billing_address', 'place_of_supply',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_CARD = 'card';

    public const PAYMENT_UPI = 'upi';

    /** Customer pays later: the amount is owed on their ledger. */
    public const PAYMENT_CREDIT = 'credit';

    public const PAYMENT_MODES = [self::PAYMENT_CASH, self::PAYMENT_CARD, self::PAYMENT_UPI, self::PAYMENT_CREDIT];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'is_interstate' => 'boolean',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'change_due' => 'decimal:2',
            'confirmation_sent_at' => 'datetime',
            'whatsapp_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The sales voucher posted for this bill.
     *
     * @return MorphOne<Voucher, $this>
     */
    public function voucher(): MorphOne
    {
        return $this->morphOne(Voucher::class, 'source');
    }
}
