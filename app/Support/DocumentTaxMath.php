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
     *   header_discount_amount:string,
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
        mixed $headerDiscount = '0',
    ): array {
        $settings ??= $scale === null ? CompanySetting::current() : null;
        $scale ??= PriceMath::scale($settings);

        $qty = self::decimal($quantity, 6);
        $price = self::decimal($unitPrice, $scale);
        $discPct = self::clampPercent($discountPercent);
        $rate = self::clampPercent($taxRatePercent);

        $merchandise = bcmul($qty, $price, $scale + 4);
        $lineDiscount = self::roundMoney(bcmul($merchandise, bcdiv($discPct, '100', 8), $scale + 4), $scale, $settings);
        $base = bcsub($merchandise, $lineDiscount, $scale + 4);
        if (bccomp($base, '0', $scale + 4) < 0) {
            $base = '0';
        }
        // This line's share of the whole-bill discount comes off after the line's own discount, before tax.
        $headerShare = self::roundMoney(Decimal::max('0', Decimal::min($headerDiscount ?? '0', $base)), $scale, $settings);
        $discountAmount = self::roundMoney(bcadd($lineDiscount, $headerShare, $scale + 4), $scale, $settings);
        $afterDiscount = bcsub($base, $headerShare, $scale + 4);
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
            'header_discount_amount' => $headerShare,
            'line_subtotal' => $lineSubtotal,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * All lines of a document with an optional whole-bill discount (percent or amount).
     * The bill discount is spread over the lines by their value after the line discount;
     * the last line takes the rounding remainder. Each line's tax is then on its discounted value.
     *
     * @param  list<array{quantity:mixed,unit_price:mixed,discount_percent:mixed,tax_rate:mixed}>  $inputs
     * @return list<array{merchandise:string,discount_amount:string,header_discount_amount:string,line_subtotal:string,tax_amount:string,line_total:string}>
     */
    public static function lines(
        array $inputs,
        ?string $headerDiscountType,
        mixed $headerDiscountValue,
        bool $pricesIncludeTax,
        ?CompanySetting $settings = null,
        ?int $scale = null,
    ): array {
        $settings ??= $scale === null ? CompanySetting::current() : null;
        $scale ??= PriceMath::scale($settings);

        $bases = [];
        foreach ($inputs as $input) {
            $plain = self::line($input['quantity'], $input['unit_price'], $input['discount_percent'] ?? 0, $input['tax_rate'] ?? 0, $pricesIncludeTax, $settings, $scale);
            $bases[] = bcsub($plain['merchandise'], $plain['discount_amount'], $scale + 4);
        }
        $shares = self::spreadHeaderDiscount($bases, $headerDiscountType, $headerDiscountValue, $settings, $scale);

        $out = [];
        foreach ($inputs as $i => $input) {
            $out[] = self::line($input['quantity'], $input['unit_price'], $input['discount_percent'] ?? 0, $input['tax_rate'] ?? 0, $pricesIncludeTax, $settings, $scale, $shares[$i] ?? '0');
        }

        return $out;
    }

    /**
     * Whole-bill discount split per line, proportional to each line's base.
     *
     * @param  list<string>  $bases  line value after the line's own discount
     * @return list<string>
     */
    public static function spreadHeaderDiscount(
        array $bases,
        ?string $type,
        mixed $value,
        ?CompanySetting $settings = null,
        ?int $scale = null,
    ): array {
        $settings ??= $scale === null ? CompanySetting::current() : null;
        $scale ??= PriceMath::scale($settings);

        $zeros = array_fill(0, count($bases), '0');
        $baseTotal = '0';
        foreach ($bases as $base) {
            $baseTotal = bcadd($baseTotal, Decimal::max('0', $base), $scale + 4);
        }
        if (($type !== 'percent' && $type !== 'amount') || ! Decimal::isPositive($value ?? '0') || ! Decimal::isPositive($baseTotal)) {
            return $zeros;
        }

        $total = $type === 'percent'
            ? self::roundMoney(bcmul($baseTotal, bcdiv(self::clampPercent($value), '100', 8), $scale + 4), $scale, $settings)
            : self::roundMoney($value, $scale, $settings);
        if (bccomp($total, self::roundMoney($baseTotal, $scale, $settings), $scale) > 0) {
            abort(422, 'The bill discount is larger than the document total.', ['X-Error-Code' => 'SALES_HEADER_DISCOUNT_TOO_LARGE']);
        }

        $last = null;
        foreach ($bases as $i => $base) {
            if (Decimal::isPositive($base)) {
                $last = $i;
            }
        }

        $shares = $zeros;
        $given = '0';
        foreach ($bases as $i => $base) {
            if (! Decimal::isPositive($base) || $i === $last) {
                continue;
            }
            $share = self::roundMoney(bcdiv(bcmul($total, $base, $scale + 8), $baseTotal, $scale + 4), $scale, $settings);
            $shares[$i] = $share;
            $given = bcadd($given, $share, $scale + 4);
        }
        if ($last !== null) {
            $shares[$last] = self::roundMoney(Decimal::max('0', bcsub($total, $given, $scale + 4)), $scale, $settings);
        }

        return $shares;
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
        return Decimal::round($value, $scale);
    }

    private static function clampPercent(mixed $value): string
    {
        $n = Decimal::max('0', Decimal::min($value ?? 0, '100'));

        return Decimal::round($n, 4);
    }

    private static function roundMoney(mixed $value, int $scale, ?CompanySetting $settings): string
    {
        if ($settings !== null) {
            return PriceMath::normalize($value, $settings);
        }

        return Decimal::round($value, $scale);
    }
}
