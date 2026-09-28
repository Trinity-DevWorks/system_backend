<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Contracts;

use App\Modules\InvoiceProof\DTOs\WalletInspectionData;

/**
 * Owners of a Safe (OneOwnerSafe or Safe{Wallet}): the company Safe, a buyer Safe,
 * or a verifier Safe. A plain wallet has no owners.
 */
interface CompanySafeOwnerLookup
{
    /**
     * @return list<string> Normalized 0x addresses. Empty when the Safe cannot be read.
     */
    public function ownersOf(string $safeAddress): array;

    /**
     * Null when the chain is not configured, so the caller cannot tell a wallet from a Safe.
     */
    public function inspect(string $address): ?WalletInspectionData;
}
