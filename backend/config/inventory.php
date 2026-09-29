<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Low Stock Threshold
    |--------------------------------------------------------------------------
    |
    | Products with stock strictly below this number are reported by the
    | low-stock endpoint. Callers may override it per request with the
    | "threshold" query parameter.
    |
    */

    'low_stock_threshold' => (int) env('LOW_STOCK_THRESHOLD', 10),

    /*
    |--------------------------------------------------------------------------
    | Store State (GST)
    |--------------------------------------------------------------------------
    |
    | Sales inside this state are taxed as CGST + SGST (half the rate each);
    | sales to customers in another state are taxed as IGST (the full rate).
    |
    */

    'home_state' => env('STORE_STATE', 'Tamil Nadu'),

    /*
    |--------------------------------------------------------------------------
    | Store Details (receipt header)
    |--------------------------------------------------------------------------
    |
    | Printed at the top of the PDF bill sent by email and WhatsApp. Keep these
    | in step with the VITE_STORE_* values used by the on-screen receipt.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Report QR Links
    |--------------------------------------------------------------------------
    |
    | Scanning a report's QR code downloads it on a phone without signing in.
    | The link is signed and expires after this many minutes. The phone must
    | be able to reach REPORT_LINK_URL: when running locally, use this PC's
    | network address (e.g. http://192.168.1.20:8000) and start the API with
    | `php artisan serve --host=0.0.0.0`. Empty = APP_URL.
    |
    */

    'report_link_url' => env('REPORT_LINK_URL') ?: env('APP_URL', 'http://localhost:8000'),

    'report_link_minutes' => (int) env('REPORT_LINK_MINUTES', 30),

    'store' => [
        'address' => env('STORE_ADDRESS', ''),
        'phone' => env('STORE_PHONE', ''),
        'gstin' => env('STORE_GSTIN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Country Code
    |--------------------------------------------------------------------------
    |
    | Prefixed to local 10-digit mobile numbers when normalising them to
    | E.164 (e.g. 9876543210 -> +919876543210).
    |
    */

    'default_country_code' => (string) env('DEFAULT_COUNTRY_CODE', '91'),

];
