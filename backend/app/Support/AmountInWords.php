<?php

namespace App\Support;

/**
 * Rupee amounts in words using the Indian system (thousand, lakh, crore),
 * as printed on tax invoices: 120000.50 => "Rupees One Lakh Twenty Thousand
 * and Fifty Paise Only".
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(string|int|float $amount): string
    {
        $cents = abs(Money::toCents($amount));
        $rupees = intdiv($cents, 100);
        $paise = $cents % 100;

        $words = $rupees > 0 ? 'Rupees '.self::number($rupees) : '';

        if ($paise > 0) {
            $words .= ($words === '' ? '' : ' and ').self::number($paise).' Paise';
        }

        return ($words === '' ? 'Rupees Zero' : $words).' Only';
    }

    /**
     * A whole number in words, Indian grouping: crore, lakh, thousand, hundred.
     */
    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }

        $parts = [];

        if ($n >= 10000000) {
            $parts[] = self::number(intdiv($n, 10000000)).' Crore';
            $n %= 10000000;
        }

        foreach ([100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $unit => $label) {
            if ($n >= $unit) {
                $parts[] = self::belowHundred(intdiv($n, $unit)).' '.$label;
                $n %= $unit;
            }
        }

        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
    }
}
