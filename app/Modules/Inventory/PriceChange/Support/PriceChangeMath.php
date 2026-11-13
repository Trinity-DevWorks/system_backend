<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Support;

use App\Support\Decimal;

/**
 * Bulk price rules and retail rounding steps. Company decimal places are applied afterwards
 * by PriceMath::normalize.
 */
final class PriceChangeMath
{
    /** @var list<string> */
    public const RULES = ['percent', 'amount', 'margin'];

    /** @var list<string> */
    public const ROUNDINGS = ['none', '0.05', '0.25', '0.99'];

    /**
     * @return string|null null when the rule has nothing to start from (no old price, or no cost)
     */
    public static function compute(?string $old, ?string $cost, string $rule, string $value): ?string
    {
        $value = Decimal::of($value);
        $factor = Decimal::add('1', Decimal::div($value, '100', 8), 8);
        $raw = match ($rule) {
            'percent' => $old === null ? null : Decimal::mul($old, $factor, 8),
            'amount' => $old === null ? null : Decimal::add($old, $value, 8),
            'margin' => $cost === null ? null : Decimal::mul($cost, $factor, 8),
            default => null,
        };
        if ($raw === null) {
            return null;
        }

        return Decimal::isNegative($raw) ? '0' : $raw;
    }

    public static function round(string $amount, string $mode): string
    {
        $rounded = match ($mode) {
            '0.05', '0.25' => self::nearest($amount, $mode),
            '0.99' => self::charm($amount),
            default => $amount,
        };

        return Decimal::isNegative($rounded) ? '0' : $rounded;
    }

    /**
     * (new − cost) / new × 100. Null when either side is missing or the new price is zero.
     */
    public static function marginPercent(?string $price, ?string $cost): ?string
    {
        if ($price === null || $cost === null || Decimal::isZero($price, 6)) {
            return null;
        }

        $ratio = Decimal::div(Decimal::sub($price, $cost, 8), $price, 8);

        return Decimal::round(Decimal::mul($ratio, '100', 8), 4);
    }

    /**
     * (new − old) / old × 100. Null when the old price is missing or zero.
     */
    public static function changePercent(?string $old, ?string $new): ?string
    {
        if ($old === null || $new === null || Decimal::isZero($old, 6)) {
            return null;
        }

        $ratio = Decimal::div(Decimal::sub($new, $old, 8), $old, 8);

        return Decimal::round(Decimal::mul($ratio, '100', 8), 4);
    }

    private static function nearest(string $amount, string $step): string
    {
        $units = Decimal::div($amount, $step, 8);

        return Decimal::mul(Decimal::round($units, 0), $step, 6);
    }

    /** Nearest whole unit, then one cent under (10.40 → 9.99, 10.60 → 10.99). */
    private static function charm(string $amount): string
    {
        return Decimal::sub(Decimal::round($amount, 0), '0.01', 6);
    }
}
