<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Quotation
 */
class QuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            // "expired" for an open quotation past valid_until; stored status alongside.
            'status' => $this->displayStatus(),
            'stored_status' => $this->status,
            'store' => StoreResource::make($this->whenLoaded('store')),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'customer_gstin' => $this->customer_gstin,
            'date' => $this->date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'is_interstate' => $this->is_interstate,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'cgst_amount' => $this->cgst_amount,
            'sgst_amount' => $this->sgst_amount,
            'igst_amount' => $this->igst_amount,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'converted_order_id' => $this->converted_order_id,
            'converted_invoice_number' => $this->whenLoaded('convertedOrder', fn () => $this->convertedOrder?->invoice_number),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->relationLoaded('product') ? $item->product?->name : null,
                'product_code' => $item->relationLoaded('product') ? $item->product?->code : null,
                'hsn_code' => $item->relationLoaded('product') ? $item->product?->hsn_code : null,
                'unit' => $item->relationLoaded('product') ? $item->product?->unit : null,
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
            'created_by_name' => $this->created_by_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
