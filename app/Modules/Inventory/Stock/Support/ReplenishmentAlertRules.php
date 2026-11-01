<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;
use App\Support\Decimal;

/**
 * Replenishment status and suggested order quantity, computed with exact decimals.
 * Inputs may be numeric strings, ints or floats (from JSON/DB).
 */
final class ReplenishmentAlertRules
{
    /** $available is on hand + open PO + inbound in-transit. */
    public static function status(mixed $available, mixed $reorderPoint, mixed $safetyStock): ReplenishmentAlertStatus
    {
        if (! Decimal::isPositive($available)) {
            return ReplenishmentAlertStatus::OutOfStock;
        }

        if (Decimal::isPositive($safetyStock) && Decimal::cmp($available, $safetyStock) <= 0) {
            return ReplenishmentAlertStatus::BelowSafety;
        }

        if (Decimal::cmp($available, $reorderPoint) <= 0) {
            return ReplenishmentAlertStatus::BelowReorder;
        }

        return ReplenishmentAlertStatus::Ok;
    }

    public static function suggestedOrderQty(
        mixed $available,
        mixed $reorderPoint,
        mixed $reorderQty,
        mixed $maxQty,
    ): string {
        if ($maxQty !== null && Decimal::isPositive($maxQty)) {
            return self::formatQty(Decimal::max('0', Decimal::sub($maxQty, $available)));
        }

        if ($reorderQty !== null && Decimal::isPositive($reorderQty)) {
            return self::formatQty($reorderQty);
        }

        return self::formatQty(Decimal::max('0', Decimal::sub($reorderPoint, $available)));
    }

    public static function formatQty(mixed $qty): string
    {
        return Decimal::round($qty, 6);
    }
}
