<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\CompanySetting\Support\PriceMath;

/**
 * String money math for journal lines. Rounds half-up with bcmath so large
 * amounts (e.g. LBP) never pass through floats.
 */
final class JournalMath
{
    /** Working precision for intermediate results before rounding. */
    public const WORK_SCALE = 8;

    public const STORAGE_SCALE = 4;

    public static function scale(): int
    {
        return min(self::STORAGE_SCALE, PriceMath::scale());
    }

    public static function round(mixed $value, ?int $scale = null): string
    {
        $scale ??= self::scale();
        $value = self::of($value);
        $half = '0.'.str_repeat('0', $scale).'5';
        $shifted = bccomp($value, '0', self::WORK_SCALE) < 0
            ? bcsub($value, $half, self::WORK_SCALE)
            : bcadd($value, $half, self::WORK_SCALE);

        return bcadd($shifted, '0', $scale);
    }

    public static function of(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_float($value) || is_int($value)) {
            $value = number_format((float) $value, self::WORK_SCALE, '.', '');
        }
        $value = trim((string) $value);

        return is_numeric($value) ? bcadd($value, '0', self::WORK_SCALE) : '0';
    }

    public static function add(mixed $left, mixed $right): string
    {
        return bcadd(self::of($left), self::of($right), self::WORK_SCALE);
    }

    public static function sub(mixed $left, mixed $right): string
    {
        return bcsub(self::of($left), self::of($right), self::WORK_SCALE);
    }

    public static function mul(mixed $left, mixed $right): string
    {
        return bcmul(self::of($left), self::of($right), self::WORK_SCALE);
    }

    public static function neg(mixed $value): string
    {
        return bcmul(self::of($value), '-1', self::WORK_SCALE);
    }

    public static function abs(mixed $value): string
    {
        $value = self::of($value);

        return bccomp($value, '0', self::WORK_SCALE) < 0 ? self::neg($value) : $value;
    }

    public static function compare(mixed $left, mixed $right, ?int $scale = null): int
    {
        return bccomp(self::of($left), self::of($right), $scale ?? self::WORK_SCALE);
    }

    public static function isZero(mixed $value, ?int $scale = null): bool
    {
        return self::compare($value, '0', $scale ?? self::scale()) === 0;
    }

    public static function isPositive(mixed $value, ?int $scale = null): bool
    {
        return self::compare($value, '0', $scale ?? self::scale()) > 0;
    }

    public static function isNegative(mixed $value, ?int $scale = null): bool
    {
        return self::compare($value, '0', $scale ?? self::scale()) < 0;
    }

    /**
     * Stored decimal string (scale 4) for a rounded amount.
     */
    public static function store(mixed $value): string
    {
        return bcadd(self::of($value), '0', self::STORAGE_SCALE);
    }
}
