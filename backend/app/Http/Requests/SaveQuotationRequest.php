<?php

namespace App\Http\Requests;

use App\Support\GstStates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind can:quotations.manage
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->customer_gstin)) {
            $this->merge(['customer_gstin' => GstStates::normalizeGstin($this->customer_gstin)]);
        }
    }

    /**
     * Each line's unit_price is optional (defaults to the product's price).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_gstin' => ['nullable', 'string', 'regex:'.GstStates::GSTIN_PATTERN],
            'date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:date'],
            'is_interstate' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_gstin.regex' => 'Enter a valid 15-character GSTIN, e.g. 29ABCDE1234F1Z5.',
            'items.required' => 'Add at least one product.',
            'items.*.product_id.distinct' => 'Each product may appear only once; adjust the quantity instead.',
            'valid_until.after_or_equal' => 'Valid until cannot be before the quotation date.',
        ];
    }
}
