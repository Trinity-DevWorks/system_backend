<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceAttestationRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceProofVerificationData;
use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Models\InvoiceVerifier;
use App\Modules\InvoiceProof\Serializers\SalesInvoiceCanonicalSerializer;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\InvoiceProof\Support\InvoiceApprovalStatement;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\InvoiceProof\Support\InvoiceRegistryAbi;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Carbon\Carbon;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Proof check: salted Merkle root of the sealed snapshot versus the on-chain hash.
 *
 * Live invoice, customer, and item rows are not part of the verdict. A later
 * edit there leaves the snapshot and the chain unchanged.
 */
class InvoiceProofVerificationService
{
    public function __construct(
        private readonly InvoiceSnapshotService $invoiceSnapshotService,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
        private readonly InvoiceRegistryGateway $invoiceRegistryGateway,
    ) {}

    public function verifySalesInvoice(SalesInvoice $invoice, ?CompanyProfile $company = null): InvoiceProofVerificationData
    {
        $company ??= CompanyProfile::singleton();
        $invoice->loadMissing('customer');
        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        $onChain = null;
        $attestations = [];
        $chainEnabled = $this->invoiceChainRegistrationService->isConfigured();

        if ($snapshot !== null && $chainEnabled) {
            try {
                $onChain = $this->invoiceRegistryGateway->invoiceOf((string) $snapshot->id);
            } catch (Throwable) {
                $onChain = null;
            }
        }

        if ($onChain !== null) {
            try {
                $attestations = $this->withVerifierNames(
                    $this->invoiceRegistryGateway->attestationsOf((string) $snapshot->id)
                );
            } catch (Throwable) {
                $attestations = [];
            }
        }

        return $this->evaluateSalesInvoice($invoice, $snapshot, $company, $onChain, $chainEnabled, $attestations);
    }

    /**
     * @param  list<InvoiceAttestationRecord>  $attestations
     */
    public function evaluateSalesInvoice(
        SalesInvoice $invoice,
        ?InvoiceSnapshot $snapshot,
        ?CompanyProfile $company = null,
        InvoiceOnChainRecord|string|null $onChain = null,
        bool $chainEnabled = false,
        array $attestations = [],
    ): InvoiceProofVerificationData {
        if ($snapshot === null) {
            return InvoiceProofVerificationData::notRegistered();
        }

        $onChainRecord = $onChain instanceof InvoiceOnChainRecord ? $onChain : null;
        $onChainContentHash = $onChainRecord?->contentHash
            ?? (is_string($onChain) ? $onChain : null);

        $snapshotHash = self::snapshotContentHash($snapshot->canonical_json, $snapshot);
        $snapshotIntact = $snapshotHash !== null && hash_equals($snapshot->content_hash, $snapshotHash);
        $liveInvoiceMatches = $this->liveInvoiceMatches($invoice, $snapshot, $company);

        $chainMatches = null;
        if ($chainEnabled && $onChainContentHash !== null) {
            $chainMatches = $snapshotHash !== null && hash_equals(
                InvoiceProofBytes::normalizedContentHash($snapshotHash),
                InvoiceProofBytes::normalizedContentHash($onChainContentHash),
            );
        }

        if (! $snapshotIntact || $chainMatches === false) {
            $status = InvoiceProofVerificationStatus::Tampered;
        } elseif ($chainEnabled && $chainMatches !== true) {
            $status = InvoiceProofVerificationStatus::PendingChain;
        } else {
            $status = $this->statusFromChain($onChainRecord);
        }

        $canApproveAsCompany = false;
        $canApproveAsBuyer = false;
        $chainId = $chainEnabled ? (int) config('blockchain.chain_id') : null;
        $contractAddress = $chainEnabled ? $this->configuredContractAddress() : null;
        $eip712 = null;
        if ($status === InvoiceProofVerificationStatus::WaitingCompany) {
            $eip712 = $this->partyApprovalTypedData('SupplierApproval', $snapshot, $chainId, $contractAddress);
            $canApproveAsCompany = $eip712 !== null;
        } elseif ($status === InvoiceProofVerificationStatus::WaitingBuyer) {
            $eip712 = $this->partyApprovalTypedData('BuyerApproval', $snapshot, $chainId, $contractAddress);
            $canApproveAsBuyer = $eip712 !== null;
        }

        return new InvoiceProofVerificationData(
            status: $status,
            snapshotIntact: $snapshotIntact,
            liveInvoiceMatches: $liveInvoiceMatches,
            chainMatches: $chainMatches,
            chainId: $chainId,
            contractAddress: $contractAddress,
            supplierWallet: WalletAddress::nonZeroOrNull($onChainRecord?->supplierAddress),
            buyerWallet: WalletAddress::nonZeroOrNull($onChainRecord?->buyerAddress),
            proofId: (string) $snapshot->id,
            eip712: $eip712,
            canApproveAsCompany: $canApproveAsCompany,
            canApproveAsBuyer: $canApproveAsBuyer,
            blockchainNetwork: $chainEnabled ? BlockchainNetwork::key() : null,
            safeTxServiceUrl: $chainEnabled ? BlockchainNetwork::safeTxServiceUrl() : null,
            safeApiKey: $chainEnabled ? BlockchainNetwork::safeApiKey() : null,
            registeredAt: self::chainInstant($onChainRecord?->registeredAt),
            supplierApprovedAt: self::chainInstant($onChainRecord?->supplierApprovedAt),
            buyerApprovedAt: self::chainInstant($onChainRecord?->buyerApprovedAt),
            attestations: $onChainRecord !== null ? $attestations : [],
        );
    }

    /**
     * @param  list<InvoiceAttestationRecord>  $attestations
     * @return list<InvoiceAttestationRecord>
     */
    private function withVerifierNames(array $attestations): array
    {
        if ($attestations === []) {
            return [];
        }

        $names = InvoiceVerifier::query()
            ->whereIn('wallet_address', array_map(
                static fn (InvoiceAttestationRecord $attestation): string => $attestation->verifier,
                $attestations,
            ))
            ->pluck('name', 'wallet_address');

        return array_map(
            static fn (InvoiceAttestationRecord $attestation): InvoiceAttestationRecord => $attestation->withVerifierName(
                $names->get($attestation->verifier)
            ),
            $attestations,
        );
    }

    private static function chainInstant(?int $timestamp): ?string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestampUTC($timestamp)->toIso8601String();
    }

    /**
     * Diagnostic only. Status does not use this result.
     */
    private function liveInvoiceMatches(
        SalesInvoice $invoice,
        InvoiceSnapshot $snapshot,
        ?CompanyProfile $company,
    ): ?bool {
        try {
            $liveJson = SalesInvoiceCanonicalSerializer::serialize(
                $invoice,
                (string) $snapshot->id,
                $company,
            )->toJson();
        } catch (InvalidArgumentException) {
            return null;
        }

        $liveHash = self::snapshotContentHash($liveJson, $snapshot);

        return $liveHash === null ? null : hash_equals($snapshot->content_hash, $liveHash);
    }

    /**
     * Null when the JSON or the stored disclosure secret cannot be hashed.
     */
    private static function snapshotContentHash(string $canonicalJson, InvoiceSnapshot $snapshot): ?string
    {
        try {
            return CanonicalInvoiceHasher::hash($canonicalJson, (string) $snapshot->disclosure_secret);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array<string, list<array{name: string, type: string}>>,
     *     message: array{proof_id: string, content_hash: string, invoice_number: string}
     * }|null
     */
    private function partyApprovalTypedData(
        string $primaryType,
        InvoiceSnapshot $snapshot,
        ?int $chainId,
        ?string $contractAddress,
    ): ?array {
        if ($chainId === null || $chainId <= 0 || $contractAddress === null) {
            return null;
        }

        $details = $this->approvalDetailsFromSnapshot($snapshot);
        if ($details === null) {
            return null;
        }

        try {
            return $primaryType === 'SupplierApproval'
                ? InvoiceRegistryAbi::supplierApprovalTypedData(
                    $chainId,
                    $contractAddress,
                    (string) $snapshot->id,
                    $snapshot->content_hash,
                    $details['invoice_number'],
                    $details['statement'],
                )
                : InvoiceRegistryAbi::buyerApprovalTypedData(
                    $chainId,
                    $contractAddress,
                    (string) $snapshot->id,
                    $snapshot->content_hash,
                    $details['invoice_number'],
                    $details['statement'],
                );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{invoice_number: string, statement: string}|null
     */
    private function approvalDetailsFromSnapshot(InvoiceSnapshot $snapshot): ?array
    {
        try {
            $canonical = json_decode($snapshot->canonical_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($canonical)) {
            return null;
        }

        $invoiceNumber = $canonical['invoice_number'] ?? null;
        if (! is_string($invoiceNumber) || $invoiceNumber === '') {
            return null;
        }

        $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
        $companyName = is_string($supplier['name'] ?? null) ? $supplier['name'] : '';
        $grandTotal = is_string($canonical['grand_total'] ?? null) ? $canonical['grand_total'] : '';
        $currencyCode = is_string($canonical['currency_code'] ?? null) ? $canonical['currency_code'] : '';

        return [
            'invoice_number' => $invoiceNumber,
            'statement' => InvoiceApprovalStatement::approve($companyName, $invoiceNumber, $grandTotal, $currencyCode),
        ];
    }

    private function statusFromChain(?InvoiceOnChainRecord $onChain): InvoiceProofVerificationStatus
    {
        if ($onChain === null) {
            return InvoiceProofVerificationStatus::Verified;
        }

        $supplierSet = ! WalletAddress::isZero($onChain->supplierAddress);
        $buyerSet = ! WalletAddress::isZero($onChain->buyerAddress);

        return match ($onChain->status) {
            InvoiceOnChainStatus::Registered => $supplierSet
                ? InvoiceProofVerificationStatus::WaitingCompany
                : InvoiceProofVerificationStatus::Verified,
            InvoiceOnChainStatus::SupplierApproved => $buyerSet
                ? InvoiceProofVerificationStatus::WaitingBuyer
                : InvoiceProofVerificationStatus::Verified,
            InvoiceOnChainStatus::FullyApproved => InvoiceProofVerificationStatus::FullyApproved,
            default => InvoiceProofVerificationStatus::Verified,
        };
    }

    private function configuredContractAddress(): ?string
    {
        $raw = trim((string) config('blockchain.contract_address'));
        if ($raw === '') {
            return null;
        }

        try {
            return InvoiceProofBytes::address($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
