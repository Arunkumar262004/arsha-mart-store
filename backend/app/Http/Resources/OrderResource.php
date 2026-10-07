<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
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
            'order_number' => $this->order_number,
            'invoice_number' => $this->invoice_number,
            'payment_mode' => $this->payment_mode,
            'store' => StoreResource::make($this->whenLoaded('store')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            // The saved name covers a cashier whose account was deleted.
            'cashier' => ($this->relationLoaded('cashier') ? $this->cashier?->name : null) ?? $this->created_by_name,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'is_interstate' => $this->is_interstate,
            'customer_gstin' => $this->customer_gstin,
            'billing_address' => $this->billing_address,
            'place_of_supply' => $this->place_of_supply,
            'cgst_amount' => $this->cgst_amount,
            'sgst_amount' => $this->sgst_amount,
            'igst_amount' => $this->igst_amount,
            'grand_total' => $this->grand_total,
            'amount_paid' => $this->amount_paid,
            'change_due' => $this->change_due,
            'confirmation_sent_at' => $this->confirmation_sent_at?->toIso8601String(),
            'whatsapp_sent_at' => $this->whatsapp_sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
