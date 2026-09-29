<?php

namespace App\Http\Requests;

use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    /**
     * The customer who already owns the submitted mobile number, when that
     * clashes with the submitted email. Returned with the 422 so the
     * counter can offer "update that customer" instead of a dead end.
     */
    private ?Customer $phoneOwner = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->customer_email)) {
            $this->merge(['customer_email' => mb_strtolower(trim($this->customer_email))]);
        }

        if (is_string($this->customer_phone)) {
            $this->merge(['customer_phone' => Phone::normalize($this->customer_phone)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `customer_id` + `update_customer` bill an existing customer and save
     * the submitted email / name / mobile on their record in one step.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $updating = $this->boolean('update_customer') && $this->filled('customer_id');

        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id', 'required_if_accepted:update_customer'],
            'update_customer' => ['sometimes', 'boolean'],
            'customer_email' => [
                'required', 'string', 'email', 'max:255',
                Rule::when($updating, [
                    Rule::unique('customers', 'email')->ignore($this->integer('customer_id')),
                ]),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => [
                'nullable', 'string', 'regex:'.Phone::E164_PATTERN,
                Rule::when($updating, [
                    Rule::unique('customers', 'phone')->ignore($this->integer('customer_id')),
                ]),
            ],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // true when the customer is outside the store's state: IGST instead of CGST + SGST
            'interstate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            // A name is mandatory only for a brand-new customer.
            function (Validator $validator) {
                if ($validator->errors()->has('customer_email') || filled($this->customer_name) || $this->filled('customer_id')) {
                    return;
                }

                if (! Customer::where('email', $this->customer_email)->exists()) {
                    $validator->errors()->add('customer_name', 'A name is required for a new customer.');
                }
            },
            // Without an explicit customer_id, a mobile that belongs to a
            // different customer (matched by email) is a conflict to resolve.
            function (Validator $validator) {
                if ($this->filled('customer_id') || blank($this->customer_phone)
                    || $validator->errors()->hasAny(['customer_email', 'customer_phone'])) {
                    return;
                }

                $this->phoneOwner = Customer::where('phone', $this->customer_phone)
                    ->where('email', '!=', $this->customer_email)
                    ->first();

                if ($this->phoneOwner) {
                    $validator->errors()->add(
                        'customer_phone',
                        "This mobile number belongs to {$this->phoneOwner->name} ({$this->phoneOwner->email}).",
                    );
                }
            },
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if (! $this->phoneOwner) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
            'conflict' => ['type' => 'phone_owner', 'customer' => CustomerResource::make($this->phoneOwner)],
        ], 422));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_email.unique' => 'This email already belongs to another customer.',
            'customer_phone.unique' => 'This mobile number already belongs to another customer.',
            'customer_phone.regex' => 'Enter a valid mobile number, e.g. 9876543210 or +91 98765 43210.',
            'items.required' => 'Add at least one product to the order.',
            'items.*.product_id.distinct' => 'Each product may appear only once; adjust the quantity instead.',
            'items.*.product_id.exists' => 'The selected product does not exist.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
        ];
    }
}
