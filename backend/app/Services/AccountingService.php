<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts double-entry vouchers. Every module that moves money (bills,
 * purchases, payments, expenses, returns) records it through post(), so the
 * ledgers, trial balance, P&L and balance sheet are always built from one
 * balanced set of entries.
 */
class AccountingService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * Save a voucher. Each line is [account, debit, credit] where account is
     * an Account or a system account code; amounts are decimal strings or
     * floats. Zero lines are dropped. Debits must equal credits.
     *
     * @param  list<array{0: Account|string, 1: string|int|float, 2: string|int|float}>  $lines
     *
     * @throws InvalidArgumentException when the voucher does not balance
     */
    public function post(
        string $type,
        array $lines,
        Store $store,
        ?CarbonInterface $date = null,
        ?string $narration = null,
        ?Model $source = null,
        ?User $user = null,
        ?string $number = null,
    ): Voucher {
        if (! in_array($type, Voucher::TYPES, true)) {
            throw new InvalidArgumentException("Unknown voucher type [{$type}].");
        }

        $date ??= now();

        $rows = [];
        $debits = 0;
        $credits = 0;

        foreach ($lines as [$account, $debit, $credit]) {
            $debit = Money::toCents($debit);
            $credit = Money::toCents($credit);

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Voucher amounts cannot be negative.');
            }

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $rows[] = [
                'account_id' => ($account instanceof Account ? $account : Account::byCode($account))->id,
                'debit' => Money::format($debit),
                'credit' => Money::format($credit),
            ];
            $debits += $debit;
            $credits += $credit;
        }

        if ($rows === [] || $debits !== $credits) {
            throw new InvalidArgumentException(
                'Voucher does not balance: debits '.Money::format($debits).', credits '.Money::format($credits).'.'
            );
        }

        return DB::transaction(function () use ($type, $rows, $store, $date, $narration, $source, $user, $number, $debits) {
            $voucher = Voucher::create([
                'store_id' => $store->id,
                'type' => $type,
                'number' => $number ?? $this->numbers->next($type, $store, $date),
                'date' => $date->toDateString(),
                'narration' => $narration,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'amount' => Money::format($debits),
                'created_by' => $user?->id,
                'created_by_name' => $user?->name,
            ]);

            $voucher->entries()->createMany(array_map(fn (array $row) => [
                ...$row,
                'store_id' => $store->id,
                'date' => $date->toDateString(),
            ], $rows));

            return $voucher;
        });
    }

    /**
     * The sales voucher for a bill:
     *   Dr Cash / Bank / Customer (credit sale)   grand total
     *   Cr Sales                                  taxable value
     *   Cr Output CGST / SGST / IGST              tax
     */
    public function postSale(Order $order, ?User $user = null): Voucher
    {
        $order->loadMissing(['store', 'customer']);

        $debitAccount = match ($order->payment_mode) {
            Order::PAYMENT_CARD, Order::PAYMENT_UPI => Account::BANK,
            Order::PAYMENT_CREDIT => Account::forParty($order->customer),
            default => Account::CASH,
        };

        return $this->post(
            Voucher::SALES,
            [
                [$debitAccount, $order->grand_total, 0],
                [Account::SALES, 0, $order->subtotal],
                [Account::OUTPUT_CGST, 0, $order->cgst_amount],
                [Account::OUTPUT_SGST, 0, $order->sgst_amount],
                [Account::OUTPUT_IGST, 0, $order->igst_amount],
            ],
            $order->store,
            $order->created_at,
            "Sale {$order->invoice_number} to {$order->customer->name} ({$order->payment_mode})",
            $order,
            $user,
            $order->invoice_number,
        );
    }

    /**
     * Balance of an account in cents, signed: positive = debit, negative = credit.
     * The opening balance is counted only when not filtering by store, since it
     * belongs to the business as a whole.
     */
    public function balance(Account $account, ?int $storeId = null, ?CarbonInterface $upTo = null): int
    {
        $totals = $account->entries()
            ->when($storeId, fn ($q, int $id) => $q->where('store_id', $id))
            // "< next day": the date cast stores "Y-m-d 00:00:00" on SQLite, so "<= Y-m-d" would miss that day.
            ->when($upTo, fn ($q) => $q->where('date', '<', $upTo->copy()->addDay()->toDateString()))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $opening = $storeId === null ? Money::toCents($account->opening_balance ?? 0) : 0;

        return $opening + Money::toCents($totals->debit) - Money::toCents($totals->credit);
    }
}
