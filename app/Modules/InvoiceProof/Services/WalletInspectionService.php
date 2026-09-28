<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\DTOs\WalletInspectionData;
use App\Modules\InvoiceProof\Support\WalletAddress;
use Throwable;

/**
 * Form helper: tells the user what the active network holds at an address before
 * saving. Saving re-checks the declared type server-side (CompanySafeSignerGuard).
 */
class WalletInspectionService
{
    public function __construct(
        private readonly CompanySafeOwnerLookup $companySafeOwnerLookup,
    ) {}

    /**
     * Null when invoice proofs are off or the chain is not configured.
     */
    public function inspect(string $address): ?WalletInspectionData
    {
        $normalized = WalletAddress::normalize($address);
        if ($normalized === null || ! CompanySetting::current()->invoiceProofsEnabled()) {
            return null;
        }

        try {
            return $this->companySafeOwnerLookup->inspect($normalized);
        } catch (Throwable) {
            abort(503, 'Could not read the address from the chain.', [
                'X-Error-Code' => 'INVOICE_PROOF_CHAIN_FAILED',
            ]);
        }
    }
}
