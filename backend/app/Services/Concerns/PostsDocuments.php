<?php

namespace App\Services\Concerns;

use App\Models\Account;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AccountingService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Helpers shared by the purchasing services: GST on a line and reversing
 * the vouchers a document posted when it is cancelled.
 *
 * @property-read AccountingService $accounting
 */
trait PostsDocuments
{
    /**
     * GST in cents on a taxable amount. Within the state the rate is split
     * into CGST + SGST (half each, each rounded); across states it is IGST.
     *
     * @return array{cgst: int, sgst: int, igst: int}
     */
    protected function gstSplit(int $subtotal, float $rate, bool $interstate): array
    {
        return $interstate
            ? ['cgst' => 0, 'sgst' => 0, 'igst' => Money::taxOn($subtotal, $rate)]
            : ['cgst' => Money::taxOn($subtotal, $rate / 2), 'sgst' => Money::taxOn($subtotal, $rate / 2), 'igst' => 0];
    }

    /**
     * Post one journal that swaps the debits and credits of the given
     * vouchers, so their combined effect on every ledger becomes zero.
     * The original vouchers stay in the books for the audit trail.
     *
     * @param  iterable<Voucher>  $vouchers
     */
    protected function reverseVouchers(iterable $vouchers, Store $store, string $narration, Model $source, ?User $user): ?Voucher
    {
        $lines = [];
        foreach ($vouchers as $voucher) {
            foreach ($voucher->entries()->with('account')->get() as $entry) {
                $lines[] = [$entry->account ?? Account::findOrFail($entry->account_id), $entry->credit, $entry->debit];
            }
        }

        if ($lines === []) {
            return null;
        }

        return $this->accounting->post(Voucher::JOURNAL, $lines, $store, now(), $narration, $source, $user);
    }
}
