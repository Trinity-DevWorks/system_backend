<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cash step for the currency the user hands over.
 * A remainder below the round limit moves down to the previous step.
 * A remainder at or above the limit moves up to the next step.
 * No smallest unit means the amount is unchanged. No round limit means half the step.
 */
final class CurrencyCashRound
{
    public static function round(mixed $amount, mixed $smallestUnit, mixed $roundLimit): string
    {
        $value = PaymentAllocation::normalize($amount);
        $unit = self::positiveDecimal($smallestUnit);
        if ($unit === null) {
            return $value;
        }

        $steps = bcdiv($value, $unit, 0);
        $lower = PaymentAllocation::normalize(bcmul($steps, $unit, 6));
        $remainder = bcsub($value, $lower, 4);
        if (bccomp($remainder, '0', 4) === 0) {
            return $value;
        }

        $limit = self::positiveDecimal($roundLimit) ?? PaymentAllocation::normalize(bcdiv($unit, '2', 6));
        if (bccomp($remainder, $limit, 4) < 0) {
            return $lower;
        }

        return PaymentAllocation::normalize(bcadd($lower, $unit, 4));
    }

    private static function positiveDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = number_format((float) $value, 6, '.', '');
        if (bccomp($normalized, '0', 6) <= 0) {
            return null;
        }

        return $normalized;
    }
}
