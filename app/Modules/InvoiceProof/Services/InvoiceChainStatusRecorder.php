<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\InvoiceProof\DTOs\InvoiceAttestationRecord;
use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use Illuminate\Support\Carbon;

/**
 * Keeps the last status read from InvoiceRegistry on the registration row, so lists
 * can show it without one RPC call per invoice.
 *
 * Only confirmed rows are updated: pending and failed rows already say where they are,
 * and their updated_at drives the stuck-registration check.
 */
class InvoiceChainStatusRecorder
{
    /**
     * @param  list<InvoiceAttestationRecord>|null  $attestations  null keeps the stored financed_at
     */
    public function record(string $proofId, InvoiceProofVerificationStatus $status, ?array $attestations = null): void
    {
        $values = [
            'chain_status' => $status->value,
            'status_checked_at' => now(),
        ];

        if ($attestations !== null) {
            $values['financed_at'] = self::financedAt($attestations);
        }

        InvoiceChainRegistration::query()
            ->where('proof_id', $proofId)
            ->where('status', InvoiceChainRegistrationStatus::Confirmed->value)
            ->update($values);
    }

    /**
     * @param  list<InvoiceAttestationRecord>  $attestations
     */
    private static function financedAt(array $attestations): ?Carbon
    {
        foreach ($attestations as $attestation) {
            if ($attestation->role === InvoiceVerifierRole::Financier) {
                return $attestation->attestedAt !== null && $attestation->attestedAt > 0
                    ? Carbon::createFromTimestampUTC($attestation->attestedAt)
                    : now();
            }
        }

        return null;
    }
}
