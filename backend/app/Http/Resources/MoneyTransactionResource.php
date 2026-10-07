<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer receipt, supplier payment or expense.
 *
 * @mixin \App\Models\MoneyTransaction
 */
class MoneyTransactionResource extends JsonResource
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
            'kind' => $this->kind,
            'number' => $this->number,
            'store' => StoreResource::make($this->whenLoaded('store')),
            'date' => $this->date?->toDateString(),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['id', 'name', 'email', 'phone'])),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->only(['id', 'name', 'gstin', 'phone'])),
            'account' => $this->whenLoaded('account', fn () => $this->account?->only(['id', 'code', 'name'])),
            'mode' => $this->mode,
            'amount' => $this->amount,
            'tax_percent' => $this->tax_percent,
            'cgst_amount' => $this->cgst_amount,
            'sgst_amount' => $this->sgst_amount,
            'total' => $this->total,
            'paid_to' => $this->paid_to,
            'supplier_gstin' => $this->supplier_gstin,
            'reference' => $this->reference,
            'narration' => $this->narration,
            'status' => $this->status,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_by' => $this->created_by_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
