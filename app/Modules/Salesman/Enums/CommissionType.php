<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Enums;

enum CommissionType: string
{
    case None = 'none';
    case Percent = 'percent';
    case Fixed = 'fixed';
}
