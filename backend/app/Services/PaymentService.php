<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\MoneyTransaction;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Concerns\PostsDocuments;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer receipts, supplier payments and expenses. Each saves a
 * MoneyTransaction numbered from its own series and posts one voucher with
 * the same number. Cancelling keeps the record (marked cancelled) and posts
 * a reversing journal, so the books keep a full audit trail.
 */
class PaymentService
{
    use PostsDocuments;

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Money received from a customer: Dr Cash / Bank, Cr Customer ledger.
     *
     * @param  array{customer_id: int, amount: string|float, mode: string, date?: ?string, reference?: ?string, narration?: ?string}  $data
     */
    public function receipt(array $data, Store $store, ?User $user): MoneyTransaction
    {
        $customer = Customer::findOrFail($data['customer_id']);
        $amount = Money::toCents($data['amount']);

        return $this->save(MoneyTransaction::RECEIPT, $data, $store, $user, [
            'customer_id' => $customer->id,
            'amount' => Money::format($amount),
            'total' => Money::format($amount),
        ], fn () => [
            [self::cashOrBank($data['mode']), Money::format($amount), 0],
            [Account::forParty($customer), 0, Money::format($amount)],
        ], "Received from {$customer->name}");
    }

    /**
     * Money paid to a supplier: Dr Supplier ledger, Cr Cash / Bank.
     *
     * @param  array{supplier_id: int, amount: string|float, mode: string, date?: ?string, reference?: ?string, narration?: ?string}  $data
     */
    public function payment(array $data, Store $store, ?User $user): MoneyTransaction
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $amount = Money::toCents($data['amount']);

        return $this->save(MoneyTransaction::PAYMENT, $data, $store, $user, [
            'supplier_id' => $supplier->id,
            'amount' => Money::format($amount),
            'total' => Money::format($amount),
        ], fn () => [
            [Account::forParty($supplier), Money::format($amount), 0],
            [self::cashOrBank($data['mode']), 0, Money::format($amount)],
        ], "Paid to {$supplier->name}");
    }

    /**
     * A shop expense: Dr Expense account (+ Input CGST / SGST when the bill
     * carries GST we can claim), Cr Cash / Bank.
     *
     * @param  array{account_id: int, amount: string|float, tax_percent?: string|float|null, supplier_gstin?: ?string,
     *     mode: string, date?: ?string, paid_to?: ?string, reference?: ?string, narration?: ?string}  $data
     */
    public function expense(array $data, Store $store, ?User $user): MoneyTransaction
    {
        $account = Account::findOrFail($data['account_id']);
        if (! $account->is_active || ! in_array($account->group, ['indirect_expense', 'direct_expense'], true)) {
            throw ValidationException::withMessages(['account_id' => 'Choose an active expense account.']);
        }

        $amount = Money::toCents($data['amount']);
        $rate = (float) ($data['tax_percent'] ?? 0);
        $gst = $this->gstSplit($amount, $rate, false);
        $total = $amount + $gst['cgst'] + $gst['sgst'];

        return $this->save(MoneyTransaction::EXPENSE, $data, $store, $user, [
            'account_id' => $account->id,
            'amount' => Money::format($amount),
            'tax_percent' => $rate,
            'cgst_amount' => Money::format($gst['cgst']),
            'sgst_amount' => Money::format($gst['sgst']),
            'total' => Money::format($total),
            'paid_to' => $data['paid_to'] ?? null,
            'supplier_gstin' => $rate > 0 ? ($data['supplier_gstin'] ?? null) : null,
        ], fn () => [
            [$account, Money::format($amount), 0],
            [Account::INPUT_CGST, Money::format($gst['cgst']), 0],
            [Account::INPUT_SGST, Money::format($gst['sgst']), 0],
            [self::cashOrBank($data['mode']), 0, Money::format($total)],
        ], $account->name.(filled($data['paid_to'] ?? null) ? " paid to {$data['paid_to']}" : ''));
    }

    /**
     * Cancel a receipt, payment or expense by reversing its voucher.
     */
    public function cancel(MoneyTransaction $transaction, ?User $user): MoneyTransaction
    {
        return DB::transaction(function () use ($transaction, $user) {
            $transaction = MoneyTransaction::query()->lockForUpdate()->with('store')->findOrFail($transaction->id);

            if ($transaction->isCancelled()) {
                throw ValidationException::withMessages(['transaction' => 'This entry is already cancelled.']);
            }

            $this->reverseVouchers(
                $transaction->vouchers()->get(),
                $transaction->store,
                "Cancelled {$transaction->kind} {$transaction->number}",
                $transaction,
                $user,
            );

            $transaction->update(['status' => MoneyTransaction::STATUS_CANCELLED, 'cancelled_at' => now()]);

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     * @param  callable(): list<array{0: Account|string, 1: string|int, 2: string|int}>  $lines
     */
    private function save(string $kind, array $data, Store $store, ?User $user, array $attributes, callable $lines, string $title): MoneyTransaction
    {
        $date = Carbon::parse($data['date'] ?? now())->startOfDay();

        return DB::transaction(function () use ($kind, $data, $store, $user, $attributes, $lines, $title, $date) {
            $transaction = MoneyTransaction::create([
                'kind' => $kind,
                'store_id' => $store->id,
                'number' => $this->numbers->next($kind, $store, $date),
                'date' => $date->toDateString(),
                'mode' => $data['mode'],
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => MoneyTransaction::STATUS_POSTED,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name,
                ...$attributes,
            ]);

            $narration = $title.' ('.$data['mode'].')'.(filled($data['narration'] ?? null) ? ': '.$data['narration'] : '');

            $this->accounting->post(
                $kind, $lines(), $store, $date, mb_substr($narration, 0, 255), $transaction, $user, $transaction->number,
            );

            return $transaction;
        });
    }

    private static function cashOrBank(string $mode): string
    {
        return $mode === 'bank' ? Account::BANK : Account::CASH;
    }
}
