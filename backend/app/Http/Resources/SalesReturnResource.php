<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SalesReturn
 */
class SalesReturnResource extends JsonResource
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
            'number' => $this->number,
            'store' => StoreResource::make($this->whenLoaded('store')),
            'order' => $this->whenLoaded('order', fn () => $this->order?->only(['id', 'order_number', 'invoice_number', 'is_interstate', 'created_at'])),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['id', 'name', 'email', 'phone'])),
            'date' => $this->date?->toDateString(),
            'reason' => $this->reason,
            'subtotal' => $this->subtotal,
            'cgst_amount' => $this->cgst_amount,
            'sgst_amount' => $this->sgst_amount,
            'igst_amount' => $this->igst_amount,
            'tax_total' => $this->tax_total,
            'grand_total' => $this->grand_total,
            'refund_mode' => $this->refund_mode,
            'created_by' => $this->created_by_name,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_id' => $item->product_id,
                'product' => $item->relationLoaded('product') ? $item->product?->only(['id', 'name', 'code', 'hsn_code', 'unit']) : null,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_percent' => $item->tax_percent,
                'line_subtotal' => $item->line_subtotal,
                'line_tax' => $item->line_tax,
                'cgst_amount' => $item->cgst_amount,
                'sgst_amount' => $item->sgst_amount,
                'igst_amount' => $item->igst_amount,
                'line_total' => $item->line_total,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
