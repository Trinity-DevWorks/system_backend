<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

enum ReplenishmentMethod: string
{
    /** Fixed qty or reorder-point gap when below threshold. */
    case ReorderPoint = 'reorder_point';

    /** Top up toward max_qty when below reorder point. */
    case MinMax = 'min_max';

    public static function forRule(?float $maxQty): self
    {
        return $maxQty !== null && $maxQty > 0
            ? self::MinMax
            : self::ReorderPoint;
    }
}
