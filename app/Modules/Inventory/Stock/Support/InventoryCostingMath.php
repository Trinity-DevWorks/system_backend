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

    /**
     * Half-up money amount. Used for a document total (quantity × price in that unit)
     * so the currency amount is not rebuilt from a rounded piece cost.
     */
    public static function extended(mixed $quantity, mixed $unitCost): string
    {
        $qty = self::qty(is_numeric($quantity) ? abs((float) $quantity) : 0);
        $cost = self::moneyRound($unitCost);
        $raw = bcmul($qty, $cost, 8);
        $half = '0.00005';
        $shifted = bccomp($raw, '0', 8) < 0
            ? bcsub($raw, $half, 8)
            : bcadd($raw, $half, 8);

        return bcadd($shifted, '0', self::MONEY_SCALE);
    }

    /**
     * Keep unit cost at the stock storage scale (4). Currency decimals apply to totals, not to one piece.
     */
    public static function moneyRound(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::money(0);
        }
        if (is_float($value) || is_int($value)) {
            $value = number_format((float) $value, 8, '.', '');
        }
        $raw = is_numeric(trim((string) $value)) ? bcadd(trim((string) $value), '0', 8) : '0';
        $half = '0.00005';
        $shifted = bccomp($raw, '0', 8) < 0
            ? bcsub($raw, $half, 8)
            : bcadd($raw, $half, 8);

        return bcadd($shifted, '0', self::MONEY_SCALE);
    }

    public static function unitCostFromValue(string $qty, string $value): string
    {
        if (bccomp($qty, '0', self::QTY_SCALE) === 0) {
            return self::money(0);
        }

        return bcdiv($value, $qty, self::MONEY_SCALE);
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

        $raw = bcdiv(bcmul(self::moneyRound($remainingValue), $sliceQty, 12), $remainingQty, 12);

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
