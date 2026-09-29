<?php

namespace App\Support;

/**
 * Integer-cent arithmetic so totals never suffer from float rounding drift.
 */
final class Money
{
    public static function toCents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Tax on an amount, rounded half-up to the nearest cent.
     */
    public static function taxOn(int $cents, string|int|float $percent): int
    {
        $basisPoints = (int) round(((float) $percent) * 100);

        return intdiv($cents * $basisPoints + 5000, 10000);
    }
}
