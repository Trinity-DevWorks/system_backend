<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

final class InventoryCostingMath
{
    public const MONEY_SCALE = 4;

    public const QTY_SCALE = 6;

    public static function money(mixed $value): string
    {
        return number_format((float) $value, self::MONEY_SCALE, '.', '');
    }

    public static function qty(mixed $value): string
    {
        return number_format((float) $value, self::QTY_SCALE, '.', '');
    }

    public static function absQty(string $signedQty): string
    {
        if (bccomp($signedQty, '0', self::QTY_SCALE) < 0) {
            return bcmul($signedQty, '-1', self::QTY_SCALE);
        }

        return $signedQty;
    }

    public static function value(string $qty, string $unitCost): string
    {
        return bcmul($qty, $unitCost, self::MONEY_SCALE);
    }

    public static function unitCostFromValue(string $qty, string $value): string
    {
        if (bccomp($qty, '0', self::QTY_SCALE) === 0) {
            return self::money(0);
        }

        return bcdiv($value, $qty, self::MONEY_SCALE);
    }

    /**
     * New moving-average unit cost after an inbound receipt.
     */
    public static function movingAverageAfterInbound(
        string $oldQty,
        string $oldValue,
        string $inQty,
        string $inUnitCost,
    ): string {
        $newQty = bcadd($oldQty, $inQty, self::QTY_SCALE);
        $newValue = bcadd($oldValue, self::value($inQty, $inUnitCost), self::MONEY_SCALE);

        if (bccomp($newQty, '0', self::QTY_SCALE) === 0) {
            return self::money(0);
        }

        return self::unitCostFromValue($newQty, $newValue);
    }

    /**
     * Value of on-hand after an inbound when the previous qty was zero or negative.
     * Only the positive remainder is valued; the hole is already in COGS.
     *
     * @return array{qty: string, value: string}
     */
    public static function inboundAfterNonPositiveOnHand(
        string $oldQty,
        string $inQty,
        string $inUnitCost,
    ): array {
        $newQty = bcadd($oldQty, $inQty, self::QTY_SCALE);
        if (bccomp($newQty, '0', self::QTY_SCALE) <= 0) {
            return [
                'qty' => $newQty,
                'value' => self::money(0),
            ];
        }

        return [
            'qty' => $newQty,
            'value' => self::value($newQty, $inUnitCost),
        ];
    }
}
