<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

/**
 * In-store EAN-13 barcodes: "200" + 9-digit sequence + check digit.
 *
 * EAN-13 numbers starting with 2 are reserved for use inside a company (GS1), so they never
 * clash with manufacturer barcodes. The "200" prefix leaves 21–29 free for weighed / priced
 * items later.
 */
final class InStoreBarcode
{
    public const PREFIX = '200';

    private const SEQUENCE_DIGITS = 9;

    /** The barcode for a sequence number (1, 2, 3 …). */
    public static function forSequence(int $sequence): string
    {
        if ($sequence < 1 || $sequence >= 10 ** self::SEQUENCE_DIGITS) {
            throw new \InvalidArgumentException('In-store barcode sequence out of range.');
        }
        $body = self::PREFIX.str_pad((string) $sequence, self::SEQUENCE_DIGITS, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    /** Sequence number of an in-store barcode, or null when it is not one of ours. */
    public static function sequenceOf(string $barcode): ?int
    {
        if (preg_match('/^'.self::PREFIX.'(\d{'.self::SEQUENCE_DIGITS.'})\d$/', $barcode, $match) !== 1) {
            return null;
        }
        if (! self::isValid($barcode)) {
            return null;
        }

        return (int) $match[1];
    }

    /** EAN-13 check digit for the first 12 digits (weights 1,3,1,3 … from the left). */
    public static function checkDigit(string $twelveDigits): string
    {
        if (preg_match('/^\d{12}$/', $twelveDigits) !== 1) {
            throw new \InvalidArgumentException('An EAN-13 body needs 12 digits.');
        }
        $sum = 0;
        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - $sum % 10) % 10);
    }

    public static function isValid(string $barcode): bool
    {
        return preg_match('/^\d{13}$/', $barcode) === 1
            && self::checkDigit(substr($barcode, 0, 12)) === $barcode[12];
    }
}
