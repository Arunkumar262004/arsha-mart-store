<?php

namespace App\Contracts;

/**
 * A party (customer, supplier) that gets its own ledger account the first
 * time money is owed to or by it. See Account::forParty().
 */
interface HasLedger
{
    /** Unique account code, e.g. "CUS-12". */
    public function ledgerCode(): string;

    public function ledgerName(): string;

    /** "receivable" (asset) or "payable" (liability). */
    public function ledgerGroup(): string;
}
