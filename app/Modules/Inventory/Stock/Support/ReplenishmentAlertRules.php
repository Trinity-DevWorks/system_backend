<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;

final class ReplenishmentAlertRules
{
    /** $available is on hand + open PO + inbound in-transit. */
    public static function status(float $available, float $reorderPoint, float $safetyStock): ReplenishmentAlertStatus
    {
        if ($available <= 0) {
            return ReplenishmentAlertStatus::OutOfStock;
        }

        if ($safetyStock > 0 && $available <= $safetyStock) {
            return ReplenishmentAlertStatus::BelowSafety;
        }

        if ($available <= $reorderPoint) {
            return ReplenishmentAlertStatus::BelowReorder;
        }

        return ReplenishmentAlertStatus::Ok;
    }

    public static function suggestedOrderQty(
        float $available,
        float $reorderPoint,
        ?float $reorderQty,
        ?float $maxQty,
    ): string {
        if ($maxQty !== null && $maxQty > 0) {
            return self::formatQty(max(0, $maxQty - $available));
        }

        if ($reorderQty !== null && $reorderQty > 0) {
            return self::formatQty($reorderQty);
        }

        return self::formatQty(max(0, $reorderPoint - $available));
    }

    public static function formatQty(float $qty): string
    {
        return number_format($qty, 6, '.', '');
    }
}
