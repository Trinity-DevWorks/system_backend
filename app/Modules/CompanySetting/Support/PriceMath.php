<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Support;

use App\Modules\CompanySetting\Enums\PriceRoundingMode;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Support\Decimal;

final class PriceMath
{
    public static function scale(?CompanySetting $settings = null): int
    {
        $places = (int) ($settings ?? CompanySetting::current())->price_decimal_places;

        return max(0, min(6, $places));
    }

    public static function normalize(mixed $value, ?CompanySetting $settings = null): string
    {
        $settings ??= CompanySetting::current();

        return Decimal::round($value, self::scale($settings), $settings->price_rounding_mode);
    }

    public static function normalizeNullable(mixed $value, ?CompanySetting $settings = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::normalize($value, $settings);
    }

    /**
     * Exact rounding (bcmath) to $scale decimals; returns a fixed-scale numeric string.
     */
    public static function round(mixed $value, int $scale, PriceRoundingMode $mode): string
    {
        return Decimal::round($value, $scale, $mode);
    }
}
