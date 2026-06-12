<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

enum ReplenishmentAlertStatus: string
{
    case Ok = 'ok';
    case BelowReorder = 'below_reorder';
    case BelowSafety = 'below_safety';
    case OutOfStock = 'out_of_stock';
}
