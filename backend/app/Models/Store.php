<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'gstin', 'phone', 'email', 'address', 'city', 'state', 'state_code', 'pincode', 'is_active'])]
class Store extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The first store, created by the migration that introduced stores. Used
     * whenever no store is chosen (console commands, seeders, single-store use).
     */
    public static function main(): self
    {
        return static::query()->orderBy('id')->firstOrFail();
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = mb_strtoupper(trim($value));
    }

    /**
     * @param  Builder<Store>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<ProductStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
