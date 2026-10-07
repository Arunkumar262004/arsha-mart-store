<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One accounting event with balanced debit and credit lines. Created only
 * through AccountingService::post(), which checks the balance.
 */
#[Fillable(['store_id', 'type', 'number', 'date', 'narration', 'source_type', 'source_id', 'amount', 'created_by', 'created_by_name'])]
class Voucher extends Model
{
    public const SALES = 'sales';

    public const PURCHASE = 'purchase';

    public const RECEIPT = 'receipt';

    public const PAYMENT = 'payment';

    public const CONTRA = 'contra';

    public const JOURNAL = 'journal';

    public const CREDIT_NOTE = 'credit_note';

    public const DEBIT_NOTE = 'debit_note';

    public const EXPENSE = 'expense';

    public const TYPES = [
        self::SALES, self::PURCHASE, self::RECEIPT, self::PAYMENT, self::CONTRA,
        self::JOURNAL, self::CREDIT_NOTE, self::DEBIT_NOTE, self::EXPENSE,
    ];

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
        ];
    }

    /**
     * @return HasMany<VoucherEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
