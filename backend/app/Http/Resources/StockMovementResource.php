<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StockMovement
 */
class StockMovementResource extends JsonResource
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
            'type' => $this->type,
            'quantity' => $this->quantity,
            'stock_after' => $this->stock_after,
            'note' => $this->note,
            'user' => ($this->relationLoaded('user') ? $this->user?->name : null) ?? $this->user_name,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'store' => $this->whenLoaded('store', fn () => $this->store?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
