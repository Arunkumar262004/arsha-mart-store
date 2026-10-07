<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Voucher;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A manual journal or contra voucher: at least two lines, each either a
 * debit or a credit, debits equal to credits. Contra vouchers move money
 * between cash and bank accounts only.
 */
class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind can:accounts.manage
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([Voucher::JOURNAL, Voucher::CONTRA])],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer', 'distinct', Rule::exists('accounts', 'id')->where('is_active', true)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $debits = 0;
                $credits = 0;
                foreach ($this->input('lines', []) as $i => $line) {
                    $debit = Money::toCents($line['debit'] ?? 0);
                    $credit = Money::toCents($line['credit'] ?? 0);
                    if (($debit > 0) === ($credit > 0)) {
                        $validator->errors()->add("lines.{$i}.debit", 'Enter either a debit or a credit amount on each line.');
                    }
                    $debits += $debit;
                    $credits += $credit;
                }

                if ($debits !== $credits) {
                    $validator->errors()->add('lines', 'Debits ('.Money::format($debits).') and credits ('.Money::format($credits).') must be equal.');
                }

                if ($this->input('type') === Voucher::CONTRA) {
                    $ids = array_column($this->input('lines', []), 'account_id');
                    $others = Account::query()->whereKey($ids)->whereNotIn('group', ['cash', 'bank'])->exists();
                    if ($others) {
                        $validator->errors()->add('lines', 'A contra voucher can only use cash and bank accounts.');
                    }
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
            'lines.min' => 'Add at least two lines.',
            'lines.*.account_id.distinct' => 'Each account can appear only once.',
            'lines.*.account_id.exists' => 'Choose an active account.',
        ];
    }
}
