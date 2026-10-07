<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // route is behind can:settings.manage
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'gstin'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => mb_strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('stores', 'code')->ignore($this->route('store'))],
            // 2-digit state code, 10-char PAN, entity number, Z, check character.
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $store = $this->route('store');
                if ($store instanceof Store && $store->is(Store::main()) && $this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'The main store cannot be deactivated.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use capital letters and numbers only, e.g. MAIN or BR2.',
            'gstin.regex' => 'Enter a valid 15-character GSTIN, e.g. 33ABCDE1234F1Z5.',
            'state_code.regex' => 'Use the 2-digit GST state code, e.g. 33 for Tamil Nadu.',
        ];
    }
}
