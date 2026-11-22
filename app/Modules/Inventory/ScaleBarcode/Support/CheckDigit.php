<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Support;

/**
 * GS1 check digits: the mod-10 digit that ends EAN / UPC / GTIN codes, and the price check digit
 * some scales print inside an EAN-13 label (GS1 "price verifier" for 4- and 5-digit prices).
 */
final class CheckDigit
{
    /** Weighting "2-" — product digits of d×2 (tens dropped, minus carry). */
    private const W2_MINUS = [0, 2, 4, 6, 8, 9, 1, 3, 5, 7];

    /** Weighting "3" — last digit of d×3. */
    private const W3 = [0, 3, 6, 9, 2, 5, 8, 1, 4, 7];

    /** Weighting "5+". */
    private const W5_PLUS = [0, 5, 1, 6, 2, 7, 3, 8, 4, 9];

    /** Weighting "5-". */
    private const W5_MINUS = [0, 5, 9, 4, 8, 3, 7, 2, 6, 1];

    /**
     * Mod-10 check digit for a GS1 body of any length (weights 3,1,3,1… from the right).
     */
    public static function mod10(string $body): string
    {
        if (preg_match('/^\d+$/', $body) !== 1) {
            throw new \InvalidArgumentException('A GS1 body must be digits.');
        }
        $sum = 0;
        $digits = array_reverse(str_split($body));
        foreach ($digits as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - $sum % 10) % 10);
    }

    /** True when the last digit is the mod-10 check digit of the rest. */
    public static function isValidMod10(string $code): bool
    {
        if (preg_match('/^\d{2,}$/', $code) !== 1) {
            return false;
        }

        return self::mod10(substr($code, 0, -1)) === substr($code, -1);
    }

    /**
     * GS1 price check digit for a 4- or 5-digit price field; null for other lengths.
     */
    public static function price(string $digits): ?string
    {
        if (preg_match('/^\d{4}$/', $digits) === 1) {
            $d = array_map('intval', str_split($digits));
            $sum = self::W2_MINUS[$d[0]] + self::W2_MINUS[$d[1]] + self::W3[$d[2]] + self::W5_MINUS[$d[3]];

            return (string) (($sum * 3) % 10);
        }

        if (preg_match('/^\d{5}$/', $digits) === 1) {
            $d = array_map('intval', str_split($digits));
            $sum = self::W5_PLUS[$d[0]] + self::W2_MINUS[$d[1]] + self::W5_MINUS[$d[2]] + self::W5_PLUS[$d[3]] + self::W2_MINUS[$d[4]];
            $target = (10 - $sum % 10) % 10;
            $check = array_search($target, self::W5_MINUS, true);

            return $check === false ? null : (string) $check;
        }

        return null;
    }
}
