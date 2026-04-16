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

];
