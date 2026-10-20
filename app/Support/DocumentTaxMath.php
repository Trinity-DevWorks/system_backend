<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Support\PriceMath;

final class DocumentTaxMath
{
    /**
     * Line money after qty × price, optional disc%, then tax.
     *
     * Exclusive: tax is added to discounted net.
     * Inclusive: tax is backed out of the discounted price; line_total stays the discounted gross.
     *
     * @return array{
     *   merchandise:string,
     *   discount_amount:string,
     *   line_subtotal:string,
     *   tax_amount:string,
     *   line_total:string
     * }
     */
    public static function line(
        mixed $quantity,
        mixed $unitPrice,
        mixed $discountPercent,
        mixed $taxRatePercent,
        bool $pricesIncludeTax,
        ?CompanySetting $settings = null,
        ?int $scale = null,
    ): array {
        $settings ??= $scale === null ? CompanySetting::current() : null;
        $scale ??= PriceMath::scale($settings);

        $qty = self::decimal($quantity, 6);
        $price = self::decimal($unitPrice, $scale);
        $discPct = self::clampPercent($discountPercent);
        $rate = self::clampPercent($taxRatePercent);

        $merchandise = bcmul($qty, $price, $scale + 4);
        $discountAmount = self::roundMoney(bcmul($merchandise, bcdiv($discPct, '100', 8), $scale + 4), $scale, $settings);
        $afterDiscount = bcsub($merchandise, $discountAmount, $scale + 4);
        if (bccomp($afterDiscount, '0', $scale + 4) < 0) {
            $afterDiscount = '0';
        }

        if ($pricesIncludeTax && bccomp($rate, '0', 4) > 0) {
            $divisor = bcadd('1', bcdiv($rate, '100', 8), 8);
            $net = bcdiv($afterDiscount, $divisor, $scale + 4);
            $tax = bcsub($afterDiscount, $net, $scale + 4);
            $lineSubtotal = self::roundMoney($net, $scale, $settings);
            $taxAmount = self::roundMoney($tax, $scale, $settings);
            $lineTotal = self::roundMoney($afterDiscount, $scale, $settings);
        } else {
            $lineSubtotal = self::roundMoney($afterDiscount, $scale, $settings);
            $taxAmount = self::roundMoney(bcmul($lineSubtotal, bcdiv($rate, '100', 8), $scale + 4), $scale, $settings);
            $lineTotal = self::roundMoney(bcadd($lineSubtotal, $taxAmount, $scale + 4), $scale, $settings);
        }

        return [
            'merchandise' => self::roundMoney($merchandise, $scale, $settings),
            'discount_amount' => $discountAmount,
            'line_subtotal' => $lineSubtotal,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * Header rollup. Exclusive: grand = subtotal − discount + tax + adjustment.
     * Inclusive: tax is already in subtotal, so grand = subtotal − discount + adjustment.
     *
     * @param  list<array{merchandise:string,discount_amount:string,tax_amount:string}>  $lines
     * @return array{subtotal:string,discount_total:string,tax_total:string,grand_total:string,net_to_pay:string}
     */
    public static function sumTotals(
        array $lines,
        mixed $adjustment,
        bool $pricesIncludeTax,
        ?CompanySetting $settings = null,
        ?int $scale = null,
    ): array {
        $settings ??= $scale === null ? CompanySetting::current() : null;
        $scale ??= PriceMath::scale($settings);

        $subtotal = '0';
        $discountTotal = '0';
        $taxTotal = '0';
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) ($line['merchandise'] ?? '0'), $scale + 4);
            $discountTotal = bcadd($discountTotal, (string) ($line['discount_amount'] ?? '0'), $scale + 4);
            $taxTotal = bcadd($taxTotal, (string) ($line['tax_amount'] ?? '0'), $scale + 4);
        }

        $subtotal = self::roundMoney($subtotal, $scale, $settings);
        $discountTotal = self::roundMoney($discountTotal, $scale, $settings);
        $taxTotal = self::roundMoney($taxTotal, $scale, $settings);
        $adjustmentMoney = self::roundMoney($adjustment ?? '0', $scale, $settings);

        $base = bcsub($subtotal, $discountTotal, $scale + 4);
        if (! $pricesIncludeTax) {
            $base = bcadd($base, $taxTotal, $scale + 4);
        }
        $grandTotal = self::roundMoney(bcadd($base, $adjustmentMoney, $scale + 4), $scale, $settings);

        if (bccomp($grandTotal, '0', $scale) < 0) {
            abort(422, 'Invoice grand total cannot be negative.', ['X-Error-Code' => 'SALES_INVOICE_NEGATIVE_TOTAL']);
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'net_to_pay' => $grandTotal,
        ];
    }

    private static function decimal(mixed $value, int $scale): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private static function clampPercent(mixed $value): string
    {
        $n = (float) ($value ?? 0);
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 100) {
            $n = 100;
        }

        return number_format($n, 4, '.', '');
    }

    private static function roundMoney(mixed $value, int $scale, ?CompanySetting $settings): string
    {
        if ($settings !== null) {
            return PriceMath::normalize($value, $settings);
        }

        return number_format((float) $value, $scale, '.', '');
    }
}
