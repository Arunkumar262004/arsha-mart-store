<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit a ledger in the chart of accounts. Built-in (system) and
 * customer / supplier ledgers only take an opening balance here.
 */
class SaveAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind can:accounts.manage
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code'))) ?: null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->route('account') === null;

        return [
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('accounts', 'code')->ignore($this->route('account'))],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'group' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(Account::GROUPS))],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'opening_side' => ['nullable', Rule::in(['dr', 'cr'])],
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
                $account = $this->route('account');
                if ($account instanceof Account && $account->is_system && $this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'Built-in accounts cannot be deactivated.');
                }
            },
        ];
    }

    /**
     * Signed opening balance in rupees (+debit / −credit), or null when not sent.
     */
    public function signedOpening(): ?string
    {
        if (! $this->has('opening_balance')) {
            return null;
        }

        $amount = (float) ($this->validated('opening_balance') ?? 0);
        $side = $this->validated('opening_side') ?? 'dr';

        return number_format($side === 'cr' ? -$amount : $amount, 2, '.', '');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use capital letters, numbers and dashes only.',
        ];
    }
}
