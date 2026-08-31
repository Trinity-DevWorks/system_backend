<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Support;

use App\Modules\Inventory\Stock\Support\StockPipelineQuantities;

final class PurchaseOrderMaxQuantity
{
    /**
     * Max is a warehouse stock ceiling (min/max replenishment), not a raw PO line cap.
     */
    public static function hasCeiling(mixed $maxQty): bool
    {
        if ($maxQty === null || $maxQty === '') {
            return false;
        }

        return bccomp(StockPipelineQuantities::qty($maxQty), '0', 6) > 0;
    }

    /**
     * Projected = on hand + other open PO + inbound transfer + this document.
     * Equality with max is allowed.
     */
    public static function exceedsCeiling(string $projected, mixed $maxQty): bool
    {
        if (! self::hasCeiling($maxQty)) {
            return false;
        }

        return bccomp($projected, StockPipelineQuantities::qty($maxQty), 6) > 0;
    }

    public static function projected(string $committed, string $thisDocumentBaseQty): string
    {
        return bcadd(
            StockPipelineQuantities::qty($committed),
            StockPipelineQuantities::qty($thisDocumentBaseQty),
            6
        );
    }
}
