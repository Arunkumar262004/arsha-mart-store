<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Store;

/**
 * The business's name, look and legal details, set under Settings → Company.
 *
 * Name, tagline, logo and favicon brand the app (login page, sidebar, browser
 * tab). The legal details (registered name, address, GSTIN, PAN, contact) are
 * the seller printed on bills and documents; a store's own address, phone and
 * GSTIN take over when the store has them (e.g. a branch in another state).
 */
final class Branding
{
    public const DEFAULT_NAME = 'Inofex Retail';

    public const DEFAULT_TAGLINE = 'Retail billing & accounts';

    /** Legal / contact keys stored in settings. */
    public const DETAILS = ['legal_name', 'gstin', 'pan', 'phone', 'email', 'website', 'address', 'city', 'state', 'state_code', 'pincode'];

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        $values = Setting::many(['company_name', 'tagline', 'logo', 'favicon', ...self::DETAILS]);

        return [
            'company_name' => $values['company_name'] ?: self::DEFAULT_NAME,
            'tagline' => $values['tagline'] ?? self::DEFAULT_TAGLINE,
            'logo' => $values['logo'],
            'favicon' => $values['favicon'],
            ...array_intersect_key($values, array_flip(self::DETAILS)),
        ];
    }

    /**
     * Who the documents of a store are issued by.
     *
     * - name: the registered business name, else the store name
     * - branch: the store name, when it differs from that name
     * - address block: the store's when it has an address, else the company's
     * - phone, email, GSTIN: the store's, else the company's
     *
     * @return array<string, string|null>
     */
    public static function seller(?Store $store): array
    {
        // Read once per request: lists embed the seller on every row.
        $company = once(fn () => self::all());

        $name = $company['legal_name'] ?: ($store?->name ?? $company['company_name']);
        $address = filled($store?->address) ? $store : null;
        $pick = fn (string $field) => $address ? $address->{$field} : $company[$field];
        $stateCode = $pick('state_code');

        return [
            'name' => $name,
            'branch' => $store && $store->name !== $name ? $store->name : null,
            'address' => $pick('address'),
            'city' => $pick('city'),
            'state' => $pick('state') ?: GstStates::name($stateCode),
            'state_code' => $stateCode,
            'pincode' => $pick('pincode'),
            'phone' => $store?->phone ?: $company['phone'],
            'email' => $store?->email ?: $company['email'],
            'website' => $company['website'],
            'gstin' => $store?->gstin ?: $company['gstin'],
            'pan' => $company['pan'],
        ];
    }

    /**
     * Logo for PDFs as a data URI: the uploaded one, else the bundled default.
     */
    public static function pdfLogo(): string
    {
        return Setting::many(['logo'])['logo']
            ?? 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('images/logo.png')));
    }
}
