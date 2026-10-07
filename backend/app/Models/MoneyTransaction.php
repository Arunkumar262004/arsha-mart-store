<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A customer receipt, a supplier payment or an expense: money coming into or
 * going out of cash / bank. Created and cancelled through App\Services\PaymentService.
 */
#[Fillable([
    'kind', 'store_id', 'number', 'date', 'customer_id', 'supplier_id', 'account_id', 'mode',
    'amount', 'tax_percent', 'cgst_amount', 'sgst_amount', 'total', 'paid_to', 'supplier_gstin',
    'reference', 'narration', 'status', 'cancelled_at', 'created_by', 'created_by_name',
])]
class MoneyTransaction extends Model
{
    public const RECEIPT = 'receipt';

    public const PAYMENT = 'payment';

    public const EXPENSE = 'expense';

    public const MODES = ['cash', 'bank'];

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
            'date' => 'date',
            'amount' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'total' => 'decimal:2',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return MorphMany<Voucher, $this>
     */
    public function vouchers(): MorphMany
    {
        return $this->morphMany(Voucher::class, 'source');
    }
}
