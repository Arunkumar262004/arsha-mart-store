<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role_id', 'store_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The store this user works in; null = every store.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Admins and users without a store may work in (and switch between) every store.
     */
    public function worksInAllStores(): bool
    {
        return $this->isAdmin() || $this->store_id === null;
    }

    public function canAccessStore(Store|int $store): bool
    {
        return $this->worksInAllStores() || $this->store_id === ($store instanceof Store ? $store->id : $store);
    }

    /**
     * Active stores this user may switch to.
     *
     * @return Collection<int, Store>
     */
    public function accessibleStores(): Collection
    {
        return Store::active()
            ->when(! $this->worksInAllStores(), fn ($q) => $q->whereKey($this->store_id))
            ->orderBy('id')
            ->get();
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    public function isAdmin(): bool
    {
        return (bool) $this->role?->is_admin;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Everything this user may do; "settings.manage" is admin-only.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if (! $this->role) {
            return [];
        }

        $keys = $this->role->permissionKeys();

        return $this->isAdmin() ? [...$keys, 'settings.manage'] : $keys;
    }
}
