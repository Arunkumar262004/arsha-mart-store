<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // route is behind can:products.manage
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => mb_strtoupper(trim($this->code))]);
        }
    }

    /**
     * Stock is only accepted on create (as the opening quantity). Later
     * changes go through the stock-adjustment endpoint so they are audited.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->route('product') === null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-_]+$/', Rule::unique('products', 'code')->ignore($this->route('product'))],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'stock' => $creating ? ['required', 'integer', 'min:0', 'max:1000000'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, numbers, dashes or underscores only.',
            'stock.prohibited' => 'Change stock with a restock or correction instead.',
        ];
    }
}
