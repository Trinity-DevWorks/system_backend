<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Support\Decimal;

/**
 * Exact (bcmath) inventory math. Values (money) are kept at 4 decimals, quantities at 6,
 * and unit costs at 6 so items costed per gram or per piece keep their precision.
 * Results are rounded half-up, never truncated.
 */
final class InventoryCostingMath
{
    public const MONEY_SCALE = 4;

    public const QTY_SCALE = 6;

    public const UNIT_COST_SCALE = 6;

    public static function money(mixed $value): string
    {
        return Decimal::round($value, self::MONEY_SCALE);
    }

    public static function qty(mixed $value): string
    {
        return Decimal::round($value, self::QTY_SCALE);
    }

    /** Cost of one base unit (6 decimals). */
    public static function unitCost(mixed $value): string
    {
        return Decimal::round($value, self::UNIT_COST_SCALE);
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
        return self::money(Decimal::mul($qty, $unitCost, Decimal::WORK_SCALE));
    }

    /**
     * Half-up money amount. Used for a document total (quantity × price in that unit)
     * so the currency amount is not rebuilt from a rounded piece cost.
     */
    public static function extended(mixed $quantity, mixed $unitCost): string
    {
        $qty = self::qty(Decimal::abs($quantity));
        $cost = self::unitCost($unitCost);

        return self::money(Decimal::mul($qty, $cost, Decimal::WORK_SCALE));
    }

    /**
     * Half-up money value at the storage scale (4). Use unitCost() for the cost of one unit.
     */
    public static function moneyRound(mixed $value): string
    {
        return self::money($value);
    }

    public static function unitCostFromValue(string $qty, string $value): string
    {
        if (Decimal::isZero($qty, self::QTY_SCALE)) {
            return self::unitCost(0);
        }

        return self::unitCost(Decimal::div($value, $qty, Decimal::WORK_SCALE));
    }

    /**
     * Part of a money total. The part that uses up the remaining quantity takes whatever value is left,
     * so the slices add back to the original amount.
     */
    public static function remainingSlice(string $remainingValue, string $sliceQty, string $remainingQty): string
    {
        $remainingQty = self::qty($remainingQty);
        $sliceQty = self::qty($sliceQty);
        if (bccomp($remainingQty, '0', self::QTY_SCALE) <= 0 || bccomp($sliceQty, '0', self::QTY_SCALE) <= 0) {
            return self::money(0);
        }
        if (bccomp($sliceQty, $remainingQty, self::QTY_SCALE) >= 0) {
            return self::moneyRound($remainingValue);
        }

        $raw = Decimal::div(Decimal::mul(self::moneyRound($remainingValue), $sliceQty, Decimal::WORK_SCALE), $remainingQty, Decimal::WORK_SCALE);

        return self::moneyRound($raw);
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
            return self::unitCost(0);
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
