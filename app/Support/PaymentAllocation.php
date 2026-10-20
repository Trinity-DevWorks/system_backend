<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\CompanySetting\Support\PriceMath;

/**
 * Shared money checks for customer receipts and supplier payments.
 * Scale matches invoice totals (PriceMath, stored as decimal 20,4).
 */
final class PaymentAllocation
{
    public static function normalize(mixed $value): string
    {
        return PriceMath::normalize($value ?? 0);
    }

    public static function isPositive(mixed $value): bool
    {
        return bccomp(self::normalize($value), '0', 4) > 0;
    }

    public static function exceeds(mixed $amount, mixed $open): bool
    {
        return bccomp(self::normalize($amount), self::normalize($open), 4) > 0;
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return bccomp(self::normalize($left), self::normalize($right), 4) === 0;
    }

    /**
     * @param  list<mixed>  $amounts
     */
    public static function sum(array $amounts): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = bcadd($total, self::normalize($amount), 4);
        }

        return self::normalize($total);
    }

    public static function add(mixed $paidTotal, mixed $allocation): string
    {
        return self::normalize(bcadd(self::normalize($paidTotal), self::normalize($allocation), 4));
    }

    /**
     * Subtract an allocation from paid_total.
     * Returns null when the result would be negative so callers can abort
     * instead of hiding a broken allocation by flooring at zero.
     */
    public static function subtract(mixed $paidTotal, mixed $allocation): ?string
    {
        $next = bcsub(self::normalize($paidTotal), self::normalize($allocation), 4);
        if (bccomp($next, '0', 4) < 0) {
            return null;
        }

        return self::normalize($next);
    }

    public static function netToPay(mixed $grandTotal, mixed $paidTotal, mixed $creditedTotal = '0'): string
    {
        $net = bcsub(self::normalize($grandTotal), self::normalize($paidTotal), 4);
        $net = bcsub($net, self::normalize($creditedTotal), 4);
        if (bccomp($net, '0', 4) < 0) {
            return self::normalize(0);
        }

        return self::normalize($net);
    }

    /**
     * Invoice-currency amount settled by a payment-currency amount.
     * Both rates mean 1 primary = rate × that currency.
     * The same currency returns the payment amount unchanged.
     */
    public static function toInvoiceCurrency(mixed $amount, mixed $paymentRate, mixed $invoiceRate, bool $sameCurrency): string
    {
        if ($sameCurrency) {
            return self::normalize($amount);
        }

        $payment = self::positiveRate($paymentRate);
        $invoice = self::positiveRate($invoiceRate);
        $value = (float) self::normalize($amount) * (float) $invoice / (float) $payment;

        return self::normalize($value);
    }

    /**
     * Move allocation amounts so they add up to a cash-rounded header.
     * The whole change stays on the last line that remains positive.
     *
     * @param  list<mixed>  $amounts
     * @return list<string>|null
     */
    public static function amountsForRoundedHeader(array $amounts, string $roundedHeader): ?array
    {
        if ($amounts === []) {
            return null;
        }

        $next = array_map(static fn (mixed $amount): string => self::normalize($amount), $amounts);
        $target = self::normalize($roundedHeader);
        $delta = bcsub($target, self::sum($next), 4);
        if (bccomp($delta, '0', 4) === 0) {
            return $next;
        }

        for ($index = count($next) - 1; $index >= 0; $index--) {
            $candidate = bcadd($next[$index], $delta, 4);
            if (bccomp($candidate, '0', 4) > 0) {
                $next[$index] = self::normalize($candidate);

                return $next;
            }
        }

        return null;
    }

    /**
     * Amount posted to the invoice and the party ledger, plus the signed gap.
     * A gap inside the invoice currency's allowed difference closes the invoice.
     * exchange_difference = cash value in invoice currency − amount posted.
     *
     * @return array{applied_amount: string, exchange_difference: string}
     */
    public static function settle(string $converted, string $open, mixed $allowedDifference, string $exceedCode): array
    {
        $converted = self::normalize($converted);
        $open = self::normalize($open);
        if (! self::isPositive($converted) || ! self::isPositive($open)) {
            abort(422, 'Allocation does not settle any of the invoice balance.', ['X-Error-Code' => $exceedCode]);
        }

        $gap = bcsub($open, $converted, 4);
        $tolerance = ($allowedDifference === null || $allowedDifference === '')
            ? self::normalize(0)
            : self::normalize($allowedDifference);
        $absGap = bccomp($gap, '0', 4) < 0 ? bcsub('0', $gap, 4) : $gap;
        if (bccomp($absGap, $tolerance, 4) <= 0) {
            return [
                'applied_amount' => $open,
                'exchange_difference' => self::normalize(bcsub($converted, $open, 4)),
            ];
        }

        if (bccomp($gap, '0', 4) < 0) {
            return [
                'applied_amount' => $converted,
                'exchange_difference' => self::normalize(0),
            ];
        }

        return [
            'applied_amount' => $converted,
            'exchange_difference' => self::normalize(0),
        ];
    }

    private static function positiveRate(mixed $rate): string
    {
        $normalized = number_format((float) $rate, ExchangeRateSnapshot::SCALE, '.', '');
        if (bccomp($normalized, '0', ExchangeRateSnapshot::SCALE) <= 0) {
            abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => 'EXCHANGE_RATE_INVALID']);
        }

        return $normalized;
    }
}
