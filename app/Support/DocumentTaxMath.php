<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Line-level VAT math for sales/purchase documents.
 *
 * @phpstan-type DocumentTaxLineTotals array{line_subtotal: string, tax_amount: string, line_total: string}
 */
final class DocumentTaxMath
{
    public const MONEY_SCALE = 4;

    /**
     * @return DocumentTaxLineTotals
     */
    public static function calculateLine(
        string $quantity,
        string $unitPrice,
        string $taxRatePercent,
        bool $pricesIncludeTax,
    ): array {
        if (bccomp($quantity, '0', 6) <= 0) {
            throw new \InvalidArgumentException('Line quantity must be positive.');
        }

        if (bccomp($unitPrice, '0', self::MONEY_SCALE) < 0) {
            throw new \InvalidArgumentException('Unit price cannot be negative.');
        }

        $rate = self::normalizeRate($taxRatePercent);

        if ($pricesIncludeTax) {
            $lineTotal = bcmul($quantity, $unitPrice, self::MONEY_SCALE);

            if (bccomp($rate, '0', self::MONEY_SCALE) <= 0) {
                return [
                    'line_subtotal' => $lineTotal,
                    'tax_amount' => self::money(0),
                    'line_total' => $lineTotal,
                ];
            }

            $divisor = bcadd('1', bcdiv($rate, '100', 6), 6);
            $lineSubtotal = bcdiv($lineTotal, $divisor, self::MONEY_SCALE);
            $taxAmount = bcsub($lineTotal, $lineSubtotal, self::MONEY_SCALE);

            return [
                'line_subtotal' => $lineSubtotal,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ];
        }

        $lineSubtotal = bcmul($quantity, $unitPrice, self::MONEY_SCALE);

        if (bccomp($rate, '0', self::MONEY_SCALE) <= 0) {
            return [
                'line_subtotal' => $lineSubtotal,
                'tax_amount' => self::money(0),
                'line_total' => $lineSubtotal,
            ];
        }

        $taxAmount = bcmul($lineSubtotal, bcdiv($rate, '100', 6), self::MONEY_SCALE);
        $lineTotal = bcadd($lineSubtotal, $taxAmount, self::MONEY_SCALE);

        return [
            'line_subtotal' => $lineSubtotal,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * @param  list<DocumentTaxLineTotals>  $lines
     * @return array{subtotal: string, tax_total: string, grand_total: string}
     */
    public static function sumTotals(array $lines): array
    {
        $subtotal = null;
        $taxTotal = null;
        $grandTotal = null;

        foreach ($lines as $line) {
            $subtotal = self::bcaddNullable($subtotal, $line['line_subtotal']);
            $taxTotal = self::bcaddNullable($taxTotal, $line['tax_amount']);
            $grandTotal = self::bcaddNullable($grandTotal, $line['line_total']);
        }

        return [
            'subtotal' => $subtotal ?? self::money(0),
            'tax_total' => $taxTotal ?? self::money(0),
            'grand_total' => $grandTotal ?? self::money(0),
        ];
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, self::MONEY_SCALE, '.', '');
    }

    private static function normalizeRate(string $taxRatePercent): string
    {
        if (bccomp($taxRatePercent, '0', 2) < 0) {
            throw new \InvalidArgumentException('Tax rate cannot be negative.');
        }

        return number_format((float) $taxRatePercent, 2, '.', '');
    }

    private static function bcaddNullable(?string $sum, string $value): string
    {
        if ($sum === null) {
            return $value;
        }

        return bcadd($sum, $value, self::MONEY_SCALE);
    }
}
