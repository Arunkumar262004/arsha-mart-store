<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'is_active' => $this->is_active,
            'is_admin' => $this->isAdmin(),
            'role' => $this->whenLoaded('role', fn () => $this->role ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'is_admin' => $this->role->is_admin,
            ] : null),
            // null = works in every store
            'store_id' => $this->store_id,
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'code' => $this->store->code,
            ] : null),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
