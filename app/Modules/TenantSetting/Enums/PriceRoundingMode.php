<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Enums;

enum PriceRoundingMode: string
{
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';
    case Up = 'up';
    case Down = 'down';
}
