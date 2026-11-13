<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Enums;

enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';
}
