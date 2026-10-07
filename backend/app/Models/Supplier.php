<?php

namespace App\Models;

use App\Contracts\HasLedger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A vendor we buy from. Shared by every store; what we owe them is the
 * balance of their payable ledger (SUP-{id}).
 */
#[Fillable([
    'name', 'gstin', 'phone', 'email', 'contact_person', 'address', 'city', 'state', 'state_code',
    'payment_terms_days', 'is_active',
])]
class Supplier extends Model implements HasLedger
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function ledgerCode(): string
    {
        return "SUP-{$this->id}";
    }

    public function ledgerName(): string
    {
        return $this->name;
    }

    public function ledgerGroup(): string
    {
        return 'payable';
    }

    /**
     * Whether any document refers to this supplier (then it can only be deactivated).
     */
    public function hasDocuments(): bool
    {
        return $this->purchases()->exists()
            || $this->purchaseReturns()->exists()
            || MoneyTransaction::where('supplier_id', $this->id)->exists()
            || ($this->ledger()->first()?->entries()->exists() ?? false);
    }

    /**
     * @param  Builder<Supplier>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * @return HasMany<PurchaseReturn, $this>
     */
    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * The payable ledger, once created by Account::forParty().
     *
     * @return MorphOne<Account, $this>
     */
    public function ledger(): MorphOne
    {
        return $this->morphOne(Account::class, 'party');
    }
}
