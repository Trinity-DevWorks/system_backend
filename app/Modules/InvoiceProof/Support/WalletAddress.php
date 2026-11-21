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
     * EIP-7702 delegation designator: 0xef0100 || address (23 bytes).
     * MetaMask Smart Accounts leave this as eth_getCode while the account remains an EOA.
     */
    public const EIP7702_DELEGATION_PATTERN = '/^0xef0100[0-9a-fA-F]{40}$/';

    /**
     * @return list<string>
     */
    public static function optionalRule(): array
    {
        return ['nullable', 'string', 'regex:'.self::PATTERN];
    }

    /**
     * True when eth_getCode is an EIP-7702 delegation, not a deployed contract.
     */
    public static function isEip7702DelegationCode(mixed $code): bool
    {
        if (! is_string($code)) {
            return false;
        }

        return (bool) preg_match(self::EIP7702_DELEGATION_PATTERN, strtolower($code));
    }

    /**
     * True when eth_getCode is a real deployed contract (not empty, not EIP-7702).
     */
    public static function hasDeployedContractCode(mixed $code): bool
    {
        if (! is_string($code) || $code === '' || $code === '0x' || $code === '0x0') {
            return false;
        }

        return ! self::isEip7702DelegationCode($code);
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
