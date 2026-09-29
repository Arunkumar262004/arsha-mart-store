<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The React frontend runs on a different origin (Vite dev server on
    | port 5173) than this API (port 8000), so the browser needs these
    | headers before it will let the frontend read API responses.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', env('FRONTEND_URL', 'http://localhost:5173', 'https://order-inventory-system-mallow.vercel.app')),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    // Lets the React app read the file name of a report download.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 3600,

    // No cookies or sessions are used; the API is stateless.
    'supports_credentials' => false,

];
