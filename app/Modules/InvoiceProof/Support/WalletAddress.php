<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

/**
 * Public Ethereum address (0x + 40 hex). Empty becomes null. Never a private key.
 */
final class WalletAddress
{
    public const PATTERN = '/^0x[0-9a-fA-F]{40}$/';

    public const ZERO = '0x0000000000000000000000000000000000000000';

    /**
     * @return list<string>
     */
    public static function optionalRule(): array
    {
        return ['nullable', 'string', 'regex:'.self::PATTERN];
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = InvoiceProofBytes::address($trimmed);

        return self::isZero($normalized) ? null : $normalized;
    }

    public static function isZero(?string $address): bool
    {
        if ($address === null || $address === '') {
            return true;
        }

        try {
            return InvoiceProofBytes::address($address) === self::ZERO;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Wire/UI helper: zero or empty → null. */
    public static function nonZeroOrNull(?string $address): ?string
    {
        $normalized = self::normalize($address);

        return $normalized;
    }
}
