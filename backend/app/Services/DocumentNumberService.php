<?php

namespace App\Services;

use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gap-free document numbers: {STORE}/{PREFIX}/{YY-YY}/{00001}, one series per
 * store, document type and financial year (prefixes in config/documents.php).
 *
 * The counter row is locked until the surrounding transaction ends, so call
 * this inside the transaction that saves the document: if the save fails the
 * number is rolled back with it and never skipped.
 */
class DocumentNumberService
{
    public function next(string $type, Store $store, ?CarbonInterface $date = null): string
    {
        $prefix = config("documents.{$type}") ?? throw new InvalidArgumentException("Unknown document type [{$type}].");
        $year = self::financialYear($date ?? now());

        $number = DB::transaction(function () use ($type, $store, $year) {
            $key = ['store_id' => $store->id, 'type' => $type, 'financial_year' => $year];

            DB::table('document_sequences')->insertOrIgnore([...$key, 'last_number' => 0]);

            $next = (int) DB::table('document_sequences')->where($key)->lockForUpdate()->value('last_number') + 1;
            DB::table('document_sequences')->where($key)->update(['last_number' => $next]);

            return $next;
        });

        return sprintf('%s/%s/%s/%05d', $store->code, $prefix, $year, $number);
    }

    /**
     * Indian financial year (April to March) as "26-27".
     */
    public static function financialYear(CarbonInterface $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }
}
