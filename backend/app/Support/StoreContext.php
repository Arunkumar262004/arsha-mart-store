<?php

namespace App\Support;

use App\Models\Store;

/**
 * The store the current request works in, chosen by the ResolveStore
 * middleware from the X-Store-Id header and the user's store assignment.
 *
 * store() is where new bills, stock changes and documents go. scopeId() is
 * what reads filter on: null when an all-stores user picked "All stores".
 * Outside a request (console, seeders, tests) both fall back to the main store.
 */
final class StoreContext
{
    private ?Store $store = null;

    private bool $all = false;

    public function set(Store $store, bool $all = false): void
    {
        $this->store = $store;
        $this->all = $all;
    }

    public function store(): Store
    {
        return $this->store ??= Store::main();
    }

    public function id(): int
    {
        return $this->store()->id;
    }

    /** Store id to filter reads by, or null for "all stores". */
    public function scopeId(): ?int
    {
        return $this->all ? null : $this->id();
    }

    public function isAll(): bool
    {
        return $this->all;
    }
}
