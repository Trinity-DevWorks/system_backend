<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * Clerk-facing proof result: missing, intact, pending on chain, waiting for approval,
 * cancelled on chain, or no longer matching.
 */
enum InvoiceProofVerificationStatus: string
{
    case Verified = 'verified';
    case Tampered = 'tampered';
    case NotRegistered = 'not_registered';
    case PendingChain = 'pending_chain';
    case WaitingCompany = 'waiting_company';
    case WaitingBuyer = 'waiting_buyer';
    case FullyApproved = 'fully_approved';
    case Revoked = 'revoked';
    case Disputed = 'disputed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
