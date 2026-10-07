<?php

namespace App\Http\Requests;

use App\Models\PurchaseReturn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // route is behind can:returns.manage
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_id' => ['nullable', 'integer', 'exists:purchases,id'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_mode' => ['required', Rule::in(PurchaseReturn::REFUND_MODES)],
            'is_interstate' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            // Taken from the purchase when one is linked.
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }
}
