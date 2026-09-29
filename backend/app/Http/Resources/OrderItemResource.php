<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'product_code' => $this->whenLoaded('product', fn () => $this->product->code),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'tax_percent' => $this->tax_percent,
            'line_subtotal' => $this->line_subtotal,
            'line_tax' => $this->line_tax,
            'cgst_percent' => $this->cgst_percent,
            'cgst_amount' => $this->cgst_amount,
            'sgst_percent' => $this->sgst_percent,
            'sgst_amount' => $this->sgst_amount,
            'igst_percent' => $this->igst_percent,
            'igst_amount' => $this->igst_amount,
            'line_total' => $this->line_total,
        ];
    }
}
