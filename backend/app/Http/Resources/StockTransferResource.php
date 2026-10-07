<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StockTransfer
 */
class StockTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'from_store' => StoreResource::make($this->whenLoaded('fromStore')),
            'to_store' => StoreResource::make($this->whenLoaded('toStore')),
            'from_store_id' => $this->from_store_id,
            'to_store_id' => $this->to_store_id,
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'dispatched_by_name' => $this->dispatched_by_name,
            'received_by_name' => $this->received_by_name,
            'vehicle_number' => $this->vehicle_number,
            'notes' => $this->notes,
            // What the signed-in user may do with it right now.
            'can_receive' => $this->status === 'in_transit' && (bool) $user?->canAccessStore($this->to_store_id),
            'can_cancel' => $this->status === 'in_transit' && (bool) $user?->canAccessStore($this->from_store_id),
            'total_quantity' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->relationLoaded('product') ? $item->product?->name : null,
                'product_code' => $item->relationLoaded('product') ? $item->product?->code : null,
                'unit' => $item->relationLoaded('product') ? $item->product?->unit : null,
                'quantity' => $item->quantity,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
