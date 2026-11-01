<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public login rate limit
    |--------------------------------------------------------------------------
    |
    | Applied to central and tenant login routes (per IP). Tune down in
    | production if exposed publicly; raise only for trusted dev networks.
    |
    */

    'login_rate_limit_per_minute' => max(1, (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 10)),

    /*
    |--------------------------------------------------------------------------
    | Password reset rate limit
    |--------------------------------------------------------------------------
    |
    | Applied to forgot-password and reset-password routes (per IP).
    |
    */

    'password_reset_rate_limit_per_minute' => max(1, (int) env('PASSWORD_RESET_RATE_LIMIT_PER_MINUTE', 5)),

    /*
    |--------------------------------------------------------------------------
    | Central (platform admin) API
    |--------------------------------------------------------------------------
    |
    | central_token_ttl_minutes: lifetime of tokens issued by the central /login.
    | tenant_lookup_rate_limit_per_minute: public tenant lookup used by the
    | Next.js proxy (server-side, cached), so most calls share one IP.
    |
    */

    'central_token_ttl_minutes' => max(1, (int) env('CENTRAL_TOKEN_TTL_MINUTES', 720)),

    'tenant_lookup_rate_limit_per_minute' => max(1, (int) env('TENANT_LOOKUP_RATE_LIMIT_PER_MINUTE', 600)),

];
