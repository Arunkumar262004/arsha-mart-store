<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DeliveryChallan
 */
class DeliveryChallanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'store' => StoreResource::make($this->whenLoaded('store')),
            'store_id' => $this->store_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'customer_gstin' => $this->customer_gstin,
            'delivery_address' => $this->delivery_address,
            'date' => $this->date?->toDateString(),
            'vehicle_number' => $this->vehicle_number,
            'transporter' => $this->transporter,
            'notes' => $this->notes,
            'order_id' => $this->order_id,
            'invoice_number' => $this->whenLoaded('order', fn () => $this->order?->invoice_number),
            'total_quantity' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),
            // Value at the prices noted when issued, before tax.
            'approx_value' => $this->whenLoaded('items', fn () => number_format(
                $this->items->sum(fn ($item) => (float) $item->unit_price * $item->quantity), 2, '.', '',
            )),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->relationLoaded('product') ? $item->product?->name : null,
                'product_code' => $item->relationLoaded('product') ? $item->product?->code : null,
                'hsn_code' => $item->relationLoaded('product') ? $item->product?->hsn_code : null,
                'unit' => $item->relationLoaded('product') ? $item->product?->unit : null,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_percent' => $item->tax_percent,
            ])),
            'created_by_name' => $this->created_by_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
