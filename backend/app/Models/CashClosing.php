<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store's end-of-day cash count (one per store per day).
 */
#[Fillable(['store_id', 'date', 'expected_cash', 'counted_cash', 'difference', 'notes', 'closed_by', 'closed_by_name'])]
class CashClosing extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
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
     * @return array<string, mixed>
     */
    public function toReport(): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'store' => $this->store?->name,
            'date' => $this->date->toDateString(),
            'expected_cash' => $this->expected_cash,
            'counted_cash' => $this->counted_cash,
            'difference' => $this->difference,
            'notes' => $this->notes,
            'closed_by' => $this->closed_by_name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
