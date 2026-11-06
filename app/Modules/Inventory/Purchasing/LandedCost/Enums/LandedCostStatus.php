<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Enums;

enum LandedCostStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
