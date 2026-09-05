<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Support;

use App\Modules\CompanySetting\Enums\PriceRoundingMode;
use App\Modules\CompanySetting\Models\CompanySetting;

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
        $scale = self::scale($settings);
        $rounded = self::round((float) $value, $scale, $settings->price_rounding_mode);

        return number_format($rounded, $scale, '.', '');
    }

    public static function normalizeNullable(mixed $value, ?CompanySetting $settings = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::normalize($value, $settings);
    }

    public static function round(float $value, int $scale, PriceRoundingMode $mode): float
    {
        $scale = max(0, $scale);

        return match ($mode) {
            PriceRoundingMode::HalfUp => round($value, $scale, PHP_ROUND_HALF_UP),
            PriceRoundingMode::HalfEven => round($value, $scale, PHP_ROUND_HALF_EVEN),
            PriceRoundingMode::Up => self::roundAwayFromZero($value, $scale),
            PriceRoundingMode::Down => self::roundTowardZero($value, $scale),
        };
    }

    private static function roundAwayFromZero(float $value, int $scale): float
    {
        $factor = 10 ** $scale;
        if ($value >= 0) {
            return ceil($value * $factor - 1e-12) / $factor;
        }

        return floor($value * $factor + 1e-12) / $factor;
    }

    private static function roundTowardZero(float $value, int $scale): float
    {
        $factor = 10 ** $scale;
        if ($value >= 0) {
            return floor($value * $factor + 1e-12) / $factor;
        }

        return ceil($value * $factor - 1e-12) / $factor;
    }
}
