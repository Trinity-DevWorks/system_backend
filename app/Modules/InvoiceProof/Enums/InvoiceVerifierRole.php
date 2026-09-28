<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * Mirrors InvoiceRegistry.VerifierRole. None is never returned for an attestation.
 */
enum InvoiceVerifierRole: string
{
    case Auditor = 'auditor';
    case TaxAuthority = 'tax_authority';
    case Financier = 'financier';

    public const CHAIN_NONE = 0;

    public function toChain(): int
    {
        return match ($this) {
            self::Auditor => 1,
            self::TaxAuthority => 2,
            self::Financier => 3,
        };
    }

    public static function fromChain(int $value): ?self
    {
        return match ($value) {
            1 => self::Auditor,
            2 => self::TaxAuthority,
            3 => self::Financier,
            default => null,
        };
    }
}
