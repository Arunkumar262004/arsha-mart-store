<?php

namespace App\Support;

/**
 * Normalises mobile numbers to E.164 (+<country><number>) so the same
 * number typed as "98765 43210", "09876543210" or "+91-98765-43210" is
 * stored, matched and messaged identically.
 */
final class Phone
{
    public const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($raw, '+')) {
            return '+'.$digits;
        }

        $countryCode = (string) config('inventory.default_country_code');

        return match (true) {
            strlen($digits) === 10 => '+'.$countryCode.$digits,
            strlen($digits) === 11 && str_starts_with($digits, '0') => '+'.$countryCode.substr($digits, 1),
            strlen($digits) === strlen($countryCode) + 10 && str_starts_with($digits, $countryCode) => '+'.$digits,
            default => '+'.$digits, // left for validation to accept or reject
        };
    }

    public static function isValid(?string $normalized): bool
    {
        return $normalized !== null && preg_match(self::E164_PATTERN, $normalized) === 1;
    }
}
