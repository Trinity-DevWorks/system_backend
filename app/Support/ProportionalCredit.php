<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\CompanySetting\Support\PriceMath;

/**
 * Credit and debit note line money, taken from the invoice line instead of recomputed.
 * The slice that finishes a line takes whatever is still left, so a full credit matches to the cent.
 */
final class ProportionalCredit
{
    /**
     * @param  array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $invoice
     * @param  array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $used
     * @return array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    public static function forReturn(array $invoice, string $invoiceBaseQty, string $returnBaseQty, string $alreadyReturnedBase, array $used): array
    {
        $remainingQty = bcsub(self::qty($invoiceBaseQty), self::qty($alreadyReturnedBase), 6);
        if (bccomp(self::qty($returnBaseQty), $remainingQty, 6) === 0) {
            return self::remaining($invoice, $used);
        }

        return [
            'line_subtotal' => self::portion($invoice['line_subtotal'], $returnBaseQty, $invoiceBaseQty),
            'discount_amount' => self::portion($invoice['discount_amount'], $returnBaseQty, $invoiceBaseQty),
            'tax_amount' => self::portion($invoice['tax_amount'], $returnBaseQty, $invoiceBaseQty),
            'line_total' => self::portion($invoice['line_total'], $returnBaseQty, $invoiceBaseQty),
        ];
    }

    /**
     * @param  array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $invoice
     * @param  array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $used
     * @return array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    public static function forAllowance(array $invoice, string $gross, array $used): array
    {
        $gross = self::money($gross);
        $remaining = self::remaining($invoice, $used);
        if (bccomp($gross, $remaining['line_total'], 4) === 0) {
            return $remaining;
        }

        $tax = bccomp(self::money($invoice['line_total']), '0', 4) === 0
            ? self::money(0)
            : self::portion($invoice['tax_amount'], $gross, $invoice['line_total']);

        return [
            'line_subtotal' => self::money(bcsub($gross, $tax, 4)),
            'discount_amount' => self::money(0),
            'tax_amount' => $tax,
            'line_total' => $gross,
        ];
    }

    /**
     * @param  array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $invoice
     * @param  array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $used
     * @return array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    public static function remaining(array $invoice, array $used): array
    {
        return [
            'line_subtotal' => self::money(bcsub(self::money($invoice['line_subtotal']), self::money($used['line_subtotal']), 4)),
            'discount_amount' => self::money(bcsub(self::money($invoice['discount_amount']), self::money($used['discount_amount']), 4)),
            'tax_amount' => self::money(bcsub(self::money($invoice['tax_amount']), self::money($used['tax_amount']), 4)),
            'line_total' => self::money(bcsub(self::money($invoice['line_total']), self::money($used['line_total']), 4)),
        ];
    }

    /**
     * @return array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    public static function zero(): array
    {
        $zero = self::money(0);

        return [
            'line_subtotal' => $zero,
            'discount_amount' => $zero,
            'tax_amount' => $zero,
            'line_total' => $zero,
        ];
    }

    public static function portion(mixed $amount, mixed $numerator, mixed $denominator): string
    {
        $denominator = self::qty($denominator);
        if (bccomp($denominator, '0', 6) === 0) {
            return self::money(0);
        }

        $raw = bcdiv(bcmul(self::money($amount), self::qty($numerator), 8), $denominator, 8);

        return self::money($raw);
    }

    public static function money(mixed $value): string
    {
        return PriceMath::normalize($value ?? 0);
    }

    public static function qty(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
