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

    public static function netToPay(mixed $grandTotal, mixed $paidTotal, mixed $creditedTotal = 0): string
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

    private static function positiveRate(mixed $rate): string
    {
        $normalized = number_format((float) $rate, ExchangeRateSnapshot::SCALE, '.', '');
        if (bccomp($normalized, '0', ExchangeRateSnapshot::SCALE) <= 0) {
            abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => 'EXCHANGE_RATE_INVALID']);
        }

        return $normalized;
    }
}
