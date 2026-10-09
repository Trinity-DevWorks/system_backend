<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * Mirrors InvoiceRegistry.PartySide. Supplier is the company that issued the sales invoice.
 * Buyer is the company that posted a purchase invoice.
 */
enum InvoicePartySide: string
{
    case Supplier = 'supplier';
    case Buyer = 'buyer';

    public function toChain(): int
    {
        return match ($this) {
            self::Supplier => 0,
            self::Buyer => 1,
        };
    }

    public static function fromChain(int $value): ?self
    {
        return match ($value) {
            0 => self::Supplier,
            1 => self::Buyer,
            default => null,
        };
    }
}
