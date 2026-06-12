<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;

final class ReplenishmentAlertRules
{
    public static function status(float $onHand, float $reorderPoint, float $safetyStock): ReplenishmentAlertStatus
    {
        if ($onHand <= 0) {
            return ReplenishmentAlertStatus::OutOfStock;
        }

        if ($safetyStock > 0 && $onHand <= $safetyStock) {
            return ReplenishmentAlertStatus::BelowSafety;
        }

        if ($onHand <= $reorderPoint) {
            return ReplenishmentAlertStatus::BelowReorder;
        }

        return ReplenishmentAlertStatus::Ok;
    }

    public static function suggestedOrderQty(
        float $onHand,
        float $reorderPoint,
        ?float $reorderQty,
        ?float $maxQty,
    ): string {
        if ($maxQty !== null && $maxQty > 0) {
            return self::formatQty(max(0, $maxQty - $onHand));
        }

        if ($reorderQty !== null && $reorderQty > 0) {
            return self::formatQty($reorderQty);
        }

        return self::formatQty(max(0, $reorderPoint - $onHand));
    }

    public static function formatQty(float $qty): string
    {
        return number_format($qty, 6, '.', '');
    }
}
