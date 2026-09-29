<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use App\Modules\InvoiceProof\Enums\InvoiceChainCheckStatus;
use App\Modules\InvoiceProof\Enums\InvoiceChainIssueKind;
use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Models\InvoiceChainCheck;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Compares the tenant's sealed snapshots and registration rows with InvoiceRegistry.
 *
 * State pass: every snapshot is re-hashed and read back from the contract.
 * Event pass: InvoiceRegistered logs for the company wallet reveal proofs the ERP
 * never sealed. Stuck or out-of-sync registrations are re-queued; the register
 * job is idempotent when the proof is already on chain with the same hash.
 */
class InvoiceChainConsistencyService
{
    /** @var list<array<string, string|null>> */
    private array $issues = [];

    public function __construct(
        private readonly InvoiceRegistryGateway $invoiceRegistryGateway,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
        private readonly InvoiceChainStatusRecorder $invoiceChainStatusRecorder,
    ) {}

    public function isAvailable(): bool
    {
        return $this->invoiceChainRegistrationService->isConfigured();
    }

    public function latest(): ?InvoiceChainCheck
    {
        return InvoiceChainCheck::query()->latest('started_at')->latest('id')->first();
    }

    /**
     * Null when invoice proofs or the chain are not configured.
     */
    public function run(): ?InvoiceChainCheck
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $this->issues = [];
        $startedAt = now();
        $contract = InvoiceProofBytes::address((string) config('blockchain.contract_address'));
        $network = BlockchainNetwork::key();
        $checked = 0;
        $requeue = [];
        $scan = ['supplier' => null, 'from' => null, 'to' => null];
        $error = null;

        try {
            [$checked, $requeue, $knownProofIds] = $this->checkSnapshots($contract);
            $scan = $this->scanRegisteredEvents($network, $contract, $knownProofIds);
        } catch (Throwable $exception) {
            $error = Str::limit($exception->getMessage(), 500);
        }

        $status = match (true) {
            $error !== null => InvoiceChainCheckStatus::Failed,
            $this->issues !== [] => InvoiceChainCheckStatus::Issues,
            default => InvoiceChainCheckStatus::Consistent,
        };

        $check = DB::transaction(function () use ($status, $network, $contract, $checked, $requeue, $scan, $error, $startedAt): InvoiceChainCheck {
            $check = InvoiceChainCheck::query()->create([
                'status' => $status,
                'blockchain_network' => $network,
                'contract_address' => $contract,
                'checked_count' => $checked,
                'issue_count' => count($this->issues),
                'requeued_count' => $error === null ? count($requeue) : 0,
                'scanned_supplier' => $scan['supplier'],
                'scanned_from_block' => $scan['from'],
                'scanned_to_block' => $scan['to'],
                'error' => $error,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            $limit = max(1, (int) config('blockchain.consistency.max_issues', 200));
            foreach (array_slice($this->issues, 0, $limit) as $issue) {
                $check->issues()->create($issue);
            }

            return $check;
        });

        if ($error === null) {
            foreach ($requeue as $proofId) {
                $this->invoiceChainRegistrationService->dispatchRegistration($proofId);
            }
        }

        return $check->load('issues');
    }

    /**
     * @return array{0: int, 1: list<string>, 2: array<string, true>}
     */
    private function checkSnapshots(string $contract): array
    {
        $stuckBefore = now()->subMinutes(max(1, (int) config('blockchain.consistency.stuck_after_minutes', 30)));
        $registrations = InvoiceChainRegistration::query()->get()->keyBy('proof_id');
        $checked = 0;
        $requeue = [];
        $known = [];

        InvoiceSnapshot::query()->orderBy('created_at')->orderBy('id')->each(
            function (InvoiceSnapshot $snapshot) use ($contract, $stuckBefore, $registrations, &$checked, &$requeue, &$known): void {
                $checked++;
                $proofId = strtolower((string) $snapshot->id);
                $known[$proofId] = true;
                $invoiceNumber = self::invoiceNumber($snapshot);
                $sealedHash = InvoiceProofBytes::normalizedContentHash((string) $snapshot->content_hash);

                $recomputed = self::recomputedHash($snapshot);
                $altered = $recomputed === null || ! hash_equals($sealedHash, $recomputed);
                if ($altered) {
                    $this->addIssue(InvoiceChainIssueKind::SnapshotAltered, $snapshot, $invoiceNumber, $sealedHash, $recomputed);
                }

                /** @var InvoiceChainRegistration|null $registration */
                $registration = $registrations->get($snapshot->id);
                if ($registration === null) {
                    if ($snapshot->created_at !== null && $snapshot->created_at->lt($stuckBefore)) {
                        $this->addIssue(InvoiceChainIssueKind::NotSubmitted, $snapshot, $invoiceNumber, 'registered', null);
                        $requeue[] = (string) $snapshot->id;
                    }

                    return;
                }

                $registeredOn = WalletAddress::normalize($registration->contract_address);
                if (
                    $registration->status === InvoiceChainRegistrationStatus::Confirmed
                    && $registeredOn !== null
                    && $registeredOn !== $contract
                ) {
                    $this->addIssue(InvoiceChainIssueKind::OtherContract, $snapshot, $invoiceNumber, $contract, $registeredOn);

                    return;
                }

                $onChain = $this->invoiceRegistryGateway->invoiceOf((string) $snapshot->id);
                $chainHash = $onChain !== null ? InvoiceProofBytes::normalizedContentHash($onChain->contentHash) : null;

                if ($chainHash !== null && ! hash_equals($sealedHash, $chainHash)) {
                    $this->addIssue(InvoiceChainIssueKind::HashMismatch, $snapshot, $invoiceNumber, $sealedHash, $chainHash);
                    $this->recordChainStatus((string) $snapshot->id, $onChain, true);

                    return;
                }

                if ($registration->status === InvoiceChainRegistrationStatus::Confirmed) {
                    if ($chainHash === null) {
                        $this->addIssue(InvoiceChainIssueKind::MissingOnChain, $snapshot, $invoiceNumber, $sealedHash, null);
                    }
                    $this->recordChainStatus((string) $snapshot->id, $onChain, $altered);

                    return;
                }

                $since = $registration->updated_at ?? $registration->created_at;
                if (! $since instanceof Carbon || $since->gte($stuckBefore)) {
                    return;
                }

                if ($chainHash !== null) {
                    $this->addIssue(
                        InvoiceChainIssueKind::StatusOutOfSync,
                        $snapshot,
                        $invoiceNumber,
                        InvoiceChainRegistrationStatus::Confirmed->value,
                        $registration->status->value,
                    );
                    $requeue[] = (string) $snapshot->id;

                    return;
                }

                $this->addIssue(
                    InvoiceChainIssueKind::RegistrationStuck,
                    $snapshot,
                    $invoiceNumber,
                    InvoiceChainRegistrationStatus::Confirmed->value,
                    trim($registration->status->value.' '.($registration->last_error ?? '')),
                );
                $requeue[] = (string) $snapshot->id;
            }
        );

        return [$checked, $requeue, $known];
    }

    /**
     * @param  array<string, true>  $knownProofIds
     * @return array{supplier: string|null, from: int|null, to: int|null}
     */
    private function scanRegisteredEvents(string $network, string $contract, array $knownProofIds): array
    {
        $supplier = WalletAddress::normalize(CompanyProfile::singleton()->wallet_address);
        if ($supplier === null) {
            return ['supplier' => null, 'from' => null, 'to' => null];
        }

        $previous = InvoiceChainCheck::query()
            ->where('blockchain_network', $network)
            ->where('contract_address', $contract)
            ->where('scanned_supplier', $supplier)
            ->whereNotNull('scanned_to_block')
            ->latest('started_at')
            ->latest('id')
            ->first();

        $from = $previous !== null
            ? (int) $previous->scanned_to_block + 1
            : max(0, (int) config('blockchain.consistency.log_from_block', 0));
        $latest = $this->invoiceRegistryGateway->latestBlockNumber();
        if ($latest < $from) {
            return ['supplier' => $supplier, 'from' => null, 'to' => null];
        }

        $chunk = max(1, (int) config('blockchain.consistency.log_chunk_blocks', 5000));
        $maxChunks = max(1, (int) config('blockchain.consistency.log_max_chunks', 20));
        $to = min($latest, $from + ($chunk * $maxChunks) - 1);

        for ($start = $from; $start <= $to; $start += $chunk) {
            $end = min($to, $start + $chunk - 1);
            foreach ($this->invoiceRegistryGateway->registeredBySupplier($supplier, $start, $end) as $log) {
                $proofId = InvoiceProofBytes::bytes32ToProofId($log['proof_id']);
                if ($proofId !== null && isset($knownProofIds[$proofId])) {
                    continue;
                }

                $this->issues[] = [
                    'kind' => InvoiceChainIssueKind::UnknownOnChain->value,
                    'proof_id' => null,
                    'chain_proof_id' => $log['proof_id'],
                    'invoice_id' => null,
                    'invoice_number' => null,
                    'expected' => null,
                    'actual' => $log['content_hash'],
                ];
            }
        }

        return ['supplier' => $supplier, 'from' => $from, 'to' => $to];
    }

    /**
     * Attestations are read only for fully approved invoices: a financier can attest nothing earlier.
     */
    private function recordChainStatus(string $proofId, ?InvoiceOnChainRecord $onChain, bool $tampered): void
    {
        $status = match (true) {
            $tampered => InvoiceProofVerificationStatus::Tampered,
            $onChain === null => InvoiceProofVerificationStatus::PendingChain,
            default => InvoiceProofVerificationService::statusFromChain($onChain),
        };

        $attestations = null;
        if ($status === InvoiceProofVerificationStatus::FullyApproved) {
            try {
                $attestations = $this->invoiceRegistryGateway->attestationsOf($proofId);
            } catch (Throwable) {
                $attestations = null;
            }
        } elseif (! $tampered) {
            $attestations = [];
        }

        $this->invoiceChainStatusRecorder->record($proofId, $status, $attestations);
    }

    private function addIssue(
        InvoiceChainIssueKind $kind,
        InvoiceSnapshot $snapshot,
        ?string $invoiceNumber,
        ?string $expected,
        ?string $actual,
    ): void {
        $this->issues[] = [
            'kind' => $kind->value,
            'proof_id' => (string) $snapshot->id,
            'chain_proof_id' => InvoiceProofBytes::proofIdToBytes32((string) $snapshot->id),
            'invoice_id' => (string) $snapshot->invoice_id,
            'invoice_number' => $invoiceNumber,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }

    private static function recomputedHash(InvoiceSnapshot $snapshot): ?string
    {
        try {
            return InvoiceProofBytes::normalizedContentHash(
                CanonicalInvoiceHasher::hash((string) $snapshot->canonical_json, (string) $snapshot->disclosure_secret)
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function invoiceNumber(InvoiceSnapshot $snapshot): ?string
    {
        $canonical = json_decode((string) $snapshot->canonical_json, true);
        $number = is_array($canonical) ? ($canonical['invoice_number'] ?? null) : null;

        return is_string($number) && $number !== '' ? Str::limit($number, 250, '') : null;
    }

    public function abortIfDisabled(): void
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(403, 'Invoice proofs are disabled for this company.', [
                'X-Error-Code' => 'INVOICE_PROOFS_DISABLED',
            ]);
        }
    }
}
