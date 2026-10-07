<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
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
            'hsn_code' => $this->hsn_code,
            'category' => $this->category,
            'unit' => $this->unit,
            'price' => $this->price,
            'cost_price' => $this->cost_price,
            'tax_percent' => $this->tax_percent,
            // Current store (or every store in "all stores" mode).
            'stock' => $this->stock,
        ];
    }
}
