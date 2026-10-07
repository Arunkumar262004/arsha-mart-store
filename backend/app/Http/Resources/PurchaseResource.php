<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Purchase
 */
class PurchaseResource extends JsonResource
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
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'supplier_invoice_date' => $this->supplier_invoice_date?->toDateString(),
            'date' => $this->date?->toDateString(),
            'is_interstate' => $this->is_interstate,
            'payment_mode' => $this->payment_mode,
            'subtotal' => $this->subtotal,
            'cgst_amount' => $this->cgst_amount,
            'sgst_amount' => $this->sgst_amount,
            'igst_amount' => $this->igst_amount,
            'tax_total' => $this->tax_total,
            'freight' => $this->freight,
            'round_off' => $this->round_off,
            'grand_total' => $this->grand_total,
            'amount_paid' => $this->amount_paid,
            'status' => $this->status,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_by' => $this->created_by_name,
            'items_count' => $this->whenCounted('items'),
            'has_returns' => $this->whenCounted('returns', fn () => $this->returns_count > 0),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->relationLoaded('product') ? $item->product?->only(['id', 'name', 'code', 'hsn_code', 'unit']) : null,
                'quantity' => $item->quantity,
                'unit_cost' => $item->unit_cost,
                'tax_percent' => $item->tax_percent,
                'line_subtotal' => $item->line_subtotal,
                'line_tax' => $item->line_tax,
                'cgst_amount' => $item->cgst_amount,
                'sgst_amount' => $item->sgst_amount,
                'igst_amount' => $item->igst_amount,
                'line_total' => $item->line_total,
                // Set by the controller on show: still returnable to the supplier.
                'returnable' => $item->returnable,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
