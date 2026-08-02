<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL (password-reset links, etc.)
    |--------------------------------------------------------------------------
    |
    | Reset emails must open the Next.js app, not the API host/port.
    | Base URL is built as: {scheme}://{request-host}[:{port}]
    | so multi-tenant subdomains stay correct.
    |
    */

    'scheme' => env('FRONTEND_SCHEME', env('APP_ENV') === 'production' ? 'https' : 'http'),

    /*
    | Dev Next.js typically runs on 3000 while the API is on 8000.
    | Leave empty / null in production when the app is on the default HTTPS port.
    */
    'port' => env('FRONTEND_PORT', env('APP_ENV') === 'production' ? null : '3000'),

];
