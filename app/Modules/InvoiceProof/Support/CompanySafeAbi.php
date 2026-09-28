<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use InvalidArgumentException;

/**
 * ABI helpers for reading Safe owners. Selectors from `cast sig`.
 */
final class CompanySafeAbi
{
    public const GET_OWNERS = '0xa0e67e2b';

    public const OWNER = '0x8da5cb5b';

    public const GET_THRESHOLD = '0xe75235b8';

    public static function decodeUint(string $data): ?int
    {
        $hex = InvoiceProofBytes::strip0x($data);
        if (strlen($hex) < 64 || ! ctype_xdigit(substr($hex, 0, 64))) {
            return null;
        }

        $value = hexdec(ltrim(substr($hex, 0, 64), '0') ?: '0');

        return is_int($value) ? $value : null;
    }

    /**
     * @return list<string>|null
     */
    public static function decodeOwners(string $data): ?array
    {
        $hex = InvoiceProofBytes::strip0x($data);
        if (strlen($hex) < 128 || strlen($hex) % 64 !== 0) {
            return null;
        }

        $offset = hexdec(substr($hex, 0, 64));
        if ($offset !== 32) {
            return null;
        }

        $count = hexdec(substr($hex, 64, 64));
        $needed = 128 + ($count * 64);
        if (strlen($hex) < $needed) {
            return null;
        }

        $owners = [];
        for ($i = 0; $i < $count; $i++) {
            $word = substr($hex, 128 + ($i * 64), 64);
            $address = self::addressFromWord($word);
            if ($address === null) {
                return null;
            }
            $owners[] = $address;
        }

        return $owners;
    }

    public static function decodeOwner(string $data): ?string
    {
        $hex = InvoiceProofBytes::strip0x($data);
        if (strlen($hex) < 64) {
            return null;
        }

        return self::addressFromWord(substr($hex, 0, 64));
    }

    private static function addressFromWord(string $word): ?string
    {
        if (strlen($word) !== 64 || ! ctype_xdigit($word)) {
            return null;
        }

        try {
            return InvoiceProofBytes::address('0x'.substr($word, 24, 40));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
