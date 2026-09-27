<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use InvalidArgumentException;

/**
 * Encodes Laravel snapshot ids and SHA-256 hex into EVM bytes32 values.
 */
final class InvoiceProofBytes
{
    public static function proofIdToBytes32(string $uuid): string
    {
        $hex = strtolower(str_replace('-', '', $uuid));
        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Proof id must be a UUID.');
        }

        return '0x'.$hex.str_repeat('0', 32);
    }

    public static function contentHashToBytes32(string $sha256Hex): string
    {
        return '0x'.self::normalizedContentHash($sha256Hex);
    }

    public static function normalizedContentHash(string $sha256Hex): string
    {
        $hex = strtolower($sha256Hex);
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Content hash must be 32 bytes hex.');
        }

        return $hex;
    }

    public static function address(string $address): string
    {
        $hex = strtolower($address);
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        if (strlen($hex) !== 40 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Address must be 20 bytes hex.');
        }

        return '0x'.$hex;
    }

    public static function strip0x(string $hex): string
    {
        $hex = strtolower($hex);

        return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
    }
}
