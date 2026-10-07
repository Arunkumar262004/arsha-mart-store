<?php

namespace App\Http\Requests;

use App\Models\MoneyTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer receipt, supplier payment or expense. The kind comes from the
 * route (receipts / payments / expenses), set by the controller via kind().
 */
class StoreMoneyTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // routes are behind can:payments.manage / can:expenses.manage
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('supplier_gstin'))) {
            $gstin = mb_strtoupper(trim($this->input('supplier_gstin')));
            $this->merge(['supplier_gstin' => $gstin === '' ? null : $gstin]);
        }
    }

    public function kind(): string
    {
        return match (true) {
            $this->is('api/receipts*') => MoneyTransaction::RECEIPT,
            $this->is('api/payments*') => MoneyTransaction::PAYMENT,
            default => MoneyTransaction::EXPENSE,
        };
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $common = [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999', 'decimal:0,2'],
            'mode' => ['required', Rule::in(MoneyTransaction::MODES)],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],
        ];

        return $common + match ($this->kind()) {
            MoneyTransaction::RECEIPT => [
                'customer_id' => ['required', 'integer', 'exists:customers,id'],
            ],
            MoneyTransaction::PAYMENT => [
                'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            ],
            default => [
                'account_id' => [
                    'required', 'integer',
                    Rule::exists('accounts', 'id')->where('is_active', true)->whereIn('group', ['indirect_expense', 'direct_expense']),
                ],
                'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:28', 'decimal:0,2'],
                // Input GST can be claimed only on a bill with the supplier's GSTIN.
                'supplier_gstin' => [
                    'nullable', 'required_unless:tax_percent,null,0,0.00', 'string',
                    'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                ],
                'paid_to' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => 'Choose an active expense account.',
            'supplier_gstin.required_unless' => 'Enter the supplier GSTIN to claim GST on this expense.',
            'supplier_gstin.regex' => 'Enter a valid 15-character GSTIN, e.g. 33ABCDE1234F1Z5.',
        ];
    }
}
