<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Customer
 */
class CustomerResource extends JsonResource
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
            'phone' => $this->phone,
            // B2B details, filled from the last GST bill.
            'gstin' => $this->gstin,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'state_code' => $this->state_code,
            'pincode' => $this->pincode,
        ];
    }
}
