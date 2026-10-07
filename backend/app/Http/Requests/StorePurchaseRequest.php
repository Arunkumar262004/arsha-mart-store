<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // route is behind can:purchases.manage
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'supplier_invoice_number' => ['nullable', 'string', 'max:50'],
            'supplier_invoice_date' => ['nullable', 'date'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            // Omit to decide from the supplier's and store's state codes.
            'is_interstate' => ['nullable', 'boolean'],
            'payment_mode' => ['required', Rule::in(Purchase::PAYMENT_MODES)],
            // Cash / bank purchases: defaults to the grand total.
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'prohibited_if:payment_mode,credit'],
            'freight' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'Choose an active supplier.',
            'amount_paid.prohibited_if' => 'A credit purchase is paid later; leave the amount paid empty.',
        ];
    }
}
