<?php

namespace App\Http\Requests;

use App\Models\DeliveryChallan;
use App\Support\GstStates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveChallanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind can:challans.manage
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->customer_gstin)) {
            $this->merge(['customer_gstin' => GstStates::normalizeGstin($this->customer_gstin)]);
        }
    }

    /**
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
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date'],
            'purpose' => ['sometimes', Rule::in(DeliveryChallan::PURPOSES)],
            'vehicle_number' => ['nullable', 'string', 'max:20'],
            'transporter' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
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
        ];
    }
}
