<?php

namespace App\Models;

use App\Contracts\HasLedger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A ledger in the chart of accounts. System accounts (seeded by migration)
 * are referenced by code through the constants below.
 */
#[Fillable(['code', 'name', 'type', 'group', 'party_type', 'party_id', 'opening_balance', 'is_active'])]
class Account extends Model
{
    public const CASH = '1000';

    public const BANK = '1010';

    public const CLOSING_STOCK = '1100';

    public const INPUT_CGST = '1300';

    public const INPUT_SGST = '1301';

    public const INPUT_IGST = '1302';

    public const OUTPUT_CGST = '2200';

    public const OUTPUT_SGST = '2201';

    public const OUTPUT_IGST = '2202';

    public const CAPITAL = '3000';

    public const SALES = '4000';

    public const SALES_RETURNS = '4010';

    public const OTHER_INCOME = '4900';

    public const PURCHASES = '5000';

    public const PURCHASE_RETURNS = '5010';

    public const FREIGHT_INWARD = '5100';

    public const STOCK_WRITE_OFF = '6030';

    public const ROUND_OFF = '6900';

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Report groups and the account type each belongs to. */
    public const GROUPS = [
        'cash' => 'asset',
        'bank' => 'asset',
        'receivable' => 'asset',
        'current_asset' => 'asset',
        'fixed_asset' => 'asset',
        'duties_taxes' => 'liability',
        'payable' => 'liability',
        'current_liability' => 'liability',
        'loan' => 'liability',
        'capital' => 'equity',
        'sales' => 'income',
        'indirect_income' => 'income',
        'purchase' => 'expense',
        'direct_expense' => 'expense',
        'indirect_expense' => 'expense',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A system account by its code, e.g. Account::byCode(Account::CASH).
     */
    public static function byCode(string $code): self
    {
        return static::query()->where('code', $code)->firstOrFail();
    }

    /**
     * The ledger of a customer or supplier, created on first use.
     */
    public static function forParty(HasLedger&Model $party): self
    {
        return static::query()->firstOrCreate(
            ['party_type' => $party->getMorphClass(), 'party_id' => $party->getKey()],
            [
                'code' => $party->ledgerCode(),
                'name' => $party->ledgerName(),
                'type' => static::GROUPS[$party->ledgerGroup()],
                'group' => $party->ledgerGroup(),
                'opening_balance' => 0,
            ],
        );
    }

    /**
     * Debit-balance accounts (assets, expenses) grow with debits; the rest with credits.
     */
    public function isDebitNature(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    /**
     * @param  Builder<Account>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<VoucherEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class);
    }
}
