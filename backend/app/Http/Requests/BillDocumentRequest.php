<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Support\GstStates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Turning a quotation or delivery challan(s) into a bill: how it is paid,
 * plus the customer's email / name when the document has none.
 */
class BillDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // routes also require billing.create
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->customer_email)) {
            $this->merge(['customer_email' => mb_strtolower(trim($this->customer_email))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'payment_mode' => ['sometimes', Rule::in(Order::PAYMENT_MODES)],
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'prohibited_if:payment_mode,credit'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'interstate' => ['sometimes', 'boolean'],
            'place_of_supply' => ['nullable', 'string', Rule::in(GstStates::codes())],
        ];

        if ($this->routeIs('challans.invoice')) {
            $rules['challan_ids'] = ['required', 'array', 'min:1', 'max:50'];
            $rules['challan_ids.*'] = ['required', 'integer', 'distinct'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_paid.prohibited_if' => 'A credit sale is paid later; leave the amount paid empty.',
            'challan_ids.required' => 'Select at least one challan.',
        ];
    }
}
