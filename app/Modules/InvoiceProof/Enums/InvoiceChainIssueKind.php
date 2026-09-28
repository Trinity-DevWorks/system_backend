<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * Ways the tenant database and InvoiceRegistry can disagree.
 */
enum InvoiceChainIssueKind: string
{
    /** Stored snapshot JSON no longer hashes to its stored content hash. */
    case SnapshotAltered = 'snapshot_altered';

    /** Registration is confirmed, but the contract has no record for the proof. */
    case MissingOnChain = 'missing_on_chain';

    /** The contract holds a different hash than the snapshot. */
    case HashMismatch = 'hash_mismatch';

    /** Registration is confirmed on a contract other than the configured one. */
    case OtherContract = 'other_contract';

    /** The proof is on chain, but the registration row is not confirmed. */
    case StatusOutOfSync = 'status_out_of_sync';

    /** Registration stayed pending or failed longer than the allowed window. */
    case RegistrationStuck = 'registration_stuck';

    /** Snapshot has no registration row although the chain is configured. */
    case NotSubmitted = 'not_submitted';

    /** InvoiceRegistered for the company wallet with no matching snapshot. */
    case UnknownOnChain = 'unknown_on_chain';

    /**
     * The check re-queued registration, so a confirmed registration afterwards means it is fixed.
     */
    public function resolvedByRegistration(): bool
    {
        return in_array($this, [self::StatusOutOfSync, self::RegistrationStuck, self::NotSubmitted], true);
    }
}
