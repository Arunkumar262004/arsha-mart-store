<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // route is behind can:suppliers.manage
    }

    protected function prepareForValidation(): void
    {
        $gstin = is_string($this->input('gstin')) ? mb_strtoupper(trim($this->input('gstin'))) : $this->input('gstin');

        $this->merge(['gstin' => $gstin === '' ? null : $gstin]);

        // A GSTIN starts with the state code; fill it in when not given.
        if (blank($this->input('state_code')) && is_string($gstin) && preg_match('/^[0-9]{2}/', $gstin)) {
            $this->merge(['state_code' => substr($gstin, 0, 2)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'gstin' => [
                'nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                Rule::unique('suppliers', 'gstin')->ignore($this->route('supplier')),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            // Amount we already owe the supplier when we start using the software.
            'opening_balance' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gstin.regex' => 'Enter a valid 15-character GSTIN, e.g. 33ABCDE1234F1Z5.',
            'gstin.unique' => 'Another supplier already has this GSTIN.',
            'state_code.regex' => 'Use the 2-digit GST state code, e.g. 33 for Tamil Nadu.',
        ];
    }
}
