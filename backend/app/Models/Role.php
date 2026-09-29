<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'description'])]
class Role extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Permission keys this role holds. Admin implicitly holds all of them.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        if ($this->is_admin) {
            return array_keys(config('permissions'));
        }

        return $this->grants->pluck('permission')->sort()->values()->all();
    }

    /**
     * @param  list<string>  $keys  keys from config/permissions.php
     */
    public function syncPermissions(array $keys): void
    {
        DB::transaction(function () use ($keys) {
            $this->grants()->delete();
            $this->grants()->createMany(
                array_map(fn (string $key) => ['permission' => $key], array_values(array_unique($keys)))
            );
        });

        $this->unsetRelation('grants');
    }
}
