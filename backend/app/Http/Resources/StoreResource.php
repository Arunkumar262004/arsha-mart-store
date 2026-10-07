<?php

namespace App\Http\Resources;

use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Store
 */
class StoreResource extends JsonResource
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
            'code' => $this->code,
            'gstin' => $this->gstin,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'state_code' => $this->state_code,
            'pincode' => $this->pincode,
            'is_active' => $this->is_active,
            'users_count' => $this->whenCounted('users'),
            // Name, address and GSTIN to print on this store's documents.
            'seller' => Branding::seller($this->resource),
        ];
    }
}
