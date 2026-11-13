<?php

declare(strict_types=1);

return [
    'recurring' => [
        'max_catch_up' => 24,
        'preview_max' => 36,
        'run_hour' => '01:00',
    ],
    'deferrals' => [
        'max_periods' => 120,
        'strict_balance_check' => false,
        'warn_on_year_close' => true,
    ],
];
