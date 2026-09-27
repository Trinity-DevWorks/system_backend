<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Contracts;

/**
 * Owners of the company Safe (OneOwnerSafe or Safe{Wallet}).
 */
interface CompanySafeOwnerLookup
{
    /**
     * @return list<string> Normalized 0x addresses. Empty when the Safe cannot be read.
     */
    public function ownersOf(string $safeAddress): array;
}
