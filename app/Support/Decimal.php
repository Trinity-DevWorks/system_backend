<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\CompanySetting\Enums\PriceRoundingMode;

/**
 * What: Exact decimal arithmetic on numeric strings (bcmath), shared by every money, quantity,
 *       cost and exchange-rate helper.
 * Where: PriceMath, InventoryCostingMath, DocumentTaxMath, PaymentAllocation, JournalMath, ExchangeRateService, ...
 * Why: Floats cannot hold most decimals exactly (0.1 + 0.2 != 0.3) and lose digits on large
 *      amounts (LBP). Values only become floats at the HTTP boundary (JSON numbers); of() turns
 *      those back into the shortest decimal that represents them, i.e. what the user typed.
 */
final class Decimal
{
    /** Default precision for intermediate results (division, conversion). */
    public const WORK_SCALE = 12;

    /**
     * Canonical numeric string ("-12.50", "0", "1500000000.1234"). Non-numeric input becomes "0".
     */
    public static function of(mixed $value): string
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return '0';
            }
            // Shortest round-trip representation (serialize_precision = -1), e.g. 0.1 => "0.1".
            $value = var_export($value, true);
        }

        $string = trim((string) $value);
        if (! is_numeric($string)) {
            return '0';
        }

        if (str_contains($string, 'e') || str_contains($string, 'E')) {
            $string = self::expandExponent($string);
        }

        if (str_starts_with($string, '+')) {
            $string = substr($string, 1);
        }
        if (str_starts_with($string, '.')) {
            $string = '0'.$string;
        }
        if (str_starts_with($string, '-.')) {
            $string = '-0'.substr($string, 1);
        }

        return $string;
    }

    public static function add(mixed $left, mixed $right, int $scale = self::WORK_SCALE): string
    {
        return bcadd(self::of($left), self::of($right), $scale);
    }

    public static function sub(mixed $left, mixed $right, int $scale = self::WORK_SCALE): string
    {
        return bcsub(self::of($left), self::of($right), $scale);
    }

    public static function mul(mixed $left, mixed $right, int $scale = self::WORK_SCALE): string
    {
        return bcmul(self::of($left), self::of($right), $scale);
    }

    /**
     * Division at $scale (truncated). Division by zero returns "0" so callers keep explicit guards.
     */
    public static function div(mixed $left, mixed $right, int $scale = self::WORK_SCALE): string
    {
        $divisor = self::of($right);
        if (self::isZero($divisor)) {
            return bcadd('0', '0', $scale);
        }

        return bcdiv(self::of($left), $divisor, $scale);
    }

    public static function neg(mixed $value, int $scale = self::WORK_SCALE): string
    {
        return bcmul(self::of($value), '-1', $scale);
    }

    public static function abs(mixed $value): string
    {
        $value = self::of($value);

        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }

    public static function cmp(mixed $left, mixed $right, ?int $scale = null): int
    {
        $left = self::of($left);
        $right = self::of($right);

        return bccomp($left, $right, $scale ?? max(self::decimals($left), self::decimals($right)));
    }

    public static function isZero(mixed $value, ?int $scale = null): bool
    {
        return self::cmp($value, '0', $scale) === 0;
    }

    public static function isPositive(mixed $value, ?int $scale = null): bool
    {
        return self::cmp($value, '0', $scale) > 0;
    }

    public static function isNegative(mixed $value, ?int $scale = null): bool
    {
        return self::cmp($value, '0', $scale) < 0;
    }

    public static function min(mixed $left, mixed $right): string
    {
        return self::cmp($left, $right) <= 0 ? self::of($left) : self::of($right);
    }

    public static function max(mixed $left, mixed $right): string
    {
        return self::cmp($left, $right) >= 0 ? self::of($left) : self::of($right);
    }

    /**
     * Round to exactly $scale decimals using the company rounding mode (default half-up,
     * i.e. half away from zero). Always returns $scale digits after the point.
     */
    public static function round(mixed $value, int $scale, PriceRoundingMode $mode = PriceRoundingMode::HalfUp): string
    {
        $scale = max(0, $scale);
        $value = self::of($value);
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $work = max($scale + 2, self::decimals($absolute));

        // Truncate toward zero, then decide whether to step one unit away from zero.
        $truncated = bcadd($absolute, '0', $scale);
        $remainder = bcsub($absolute, $truncated, $work);
        $unit = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
        $half = bcdiv($unit, '2', $scale + 1);

        $awayFromZero = match ($mode) {
            PriceRoundingMode::Down => false,
            PriceRoundingMode::Up => bccomp($remainder, '0', $work) > 0,
            PriceRoundingMode::HalfUp => bccomp($remainder, $half, $work) >= 0,
            PriceRoundingMode::HalfEven => match (bccomp($remainder, $half, $work)) {
                1 => true,
                -1 => false,
                default => self::lastDigitIsOdd($truncated),
            },
        };

        $result = $awayFromZero ? bcadd($truncated, $unit, $scale) : $truncated;

        if ($negative && bccomp($result, '0', $scale) !== 0) {
            $result = '-'.$result;
        }

        return $result;
    }

    /**
     * Fixed-scale string without rounding concerns (pads or truncates; prefer round() for money).
     */
    public static function fixed(mixed $value, int $scale): string
    {
        return bcadd(self::of($value), '0', max(0, $scale));
    }

    private static function decimals(string $value): int
    {
        $dot = strpos($value, '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    private static function lastDigitIsOdd(string $value): bool
    {
        $digits = str_replace(['-', '.'], '', $value);

        return ((int) substr($digits, -1)) % 2 === 1;
    }

    private static function expandExponent(string $value): string
    {
        [$mantissa, $exponent] = preg_split('/[eE]/', $value) ?: [$value, '0'];
        $exponent = (int) $exponent;
        $mantissa = self::of($mantissa);

        if ($exponent >= 0) {
            return bcmul($mantissa, bcpow('10', (string) $exponent), self::decimals($mantissa));
        }

        $scale = self::decimals($mantissa) - $exponent;

        return rtrim(rtrim(bcdiv($mantissa, bcpow('10', (string) -$exponent), $scale), '0'), '.') ?: '0';
    }
}
