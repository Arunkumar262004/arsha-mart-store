<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Supplier
 *
 * `outstanding` (what we owe, positive) and `opening_balance` are set by the
 * controller as attributes when it has worked them out.
 */
class SupplierResource extends JsonResource
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
            'gstin' => $this->gstin,
            'phone' => $this->phone,
            'email' => $this->email,
            'contact_person' => $this->contact_person,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'state_code' => $this->state_code,
            'payment_terms_days' => $this->payment_terms_days,
            'is_active' => $this->is_active,
            'outstanding' => $this->when(isset($this->outstanding), fn () => $this->outstanding),
            'opening_balance' => $this->when(isset($this->opening_balance), fn () => $this->opening_balance),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
