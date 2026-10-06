<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Models\Tenant;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceAttestationRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceProofVerificationData;
use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Models\InvoiceVerifier;
use App\Modules\InvoiceProof\Serializers\PurchaseInvoiceCanonicalSerializer;
use App\Modules\InvoiceProof\Serializers\SalesInvoiceCanonicalSerializer;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\InvoiceProof\Support\InvoiceApprovalStatement;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\InvoiceProof\Support\InvoiceRegistryAbi;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
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
        private readonly InvoiceChainStatusRecorder $invoiceChainStatusRecorder,
    ) {}

    public function verifySalesInvoice(SalesInvoice $invoice, ?CompanyProfile $company = null): InvoiceProofVerificationData
    {
        $company ??= CompanyProfile::singleton();
        $invoice->loadMissing('customer');
        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        $onChain = null;
        $chainRead = false;
        $attestations = [];
        $attestationsRead = true;
        $chainEnabled = $this->invoiceChainRegistrationService->isConfigured();

        if ($snapshot !== null && $chainEnabled) {
            try {
                $onChain = $this->invoiceRegistryGateway->invoiceOf((string) $snapshot->id);
                $chainRead = true;
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
                $attestationsRead = false;
            }
        }

        if ($snapshot !== null && $onChain !== null && $onChain->disputedAt !== null) {
            $this->invoiceChainRegistrationService->syncDisputeFromChain((string) $snapshot->id, $onChain);
        }

        $disputeReason = null;
        if ($snapshot !== null) {
            $disputeReason = $this->disputeReasonForProof(
                (string) $snapshot->id,
                $onChain?->disputeReasonHash,
                $onChain?->disputedAt !== null,
            );
        }

        $proof = $this->evaluateSalesInvoice($invoice, $snapshot, $company, $onChain, $chainEnabled, $attestations, $disputeReason);

        if ($snapshot !== null && $chainRead) {
            $this->invoiceChainStatusRecorder->record(
                (string) $snapshot->id,
                $proof->status,
                $attestationsRead ? $attestations : null,
            );
        }

        return $proof;
    }

    public function verifyPurchaseInvoice(PurchaseInvoice $invoice, ?CompanyProfile $company = null): InvoiceProofVerificationData
    {
        $company ??= CompanyProfile::singleton();
        $invoice->loadMissing(['supplier', 'currency']);
        if ($invoice->linked_proof_id !== null && $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id) === null) {
            return $this->verifyLinkedPurchaseInvoice($invoice, $company);
        }
        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
        $onChain = null;
        $chainRead = false;
        $attestations = [];
        $attestationsRead = true;
        $chainEnabled = $this->invoiceChainRegistrationService->isConfigured();

        if ($snapshot !== null && $chainEnabled) {
            try {
                $onChain = $this->invoiceRegistryGateway->invoiceOf((string) $snapshot->id);
                $chainRead = true;
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
                $attestationsRead = false;
            }
        }

        if ($snapshot !== null && $onChain !== null && $onChain->disputedAt !== null) {
            $this->invoiceChainRegistrationService->syncDisputeFromChain((string) $snapshot->id, $onChain);
        }

        $disputeReason = null;
        if ($snapshot !== null) {
            $stored = InvoiceChainRegistration::query()->where('proof_id', $snapshot->id)->value('dispute_reason');
            $disputeReason = is_string($stored) && $stored !== '' ? $stored : null;
        }

        $proof = $this->evaluatePurchaseInvoice($invoice, $snapshot, $company, $onChain, $chainEnabled, $attestations, $disputeReason);

        if ($snapshot !== null && $chainRead) {
            $this->invoiceChainStatusRecorder->record(
                (string) $snapshot->id,
                $proof->status,
                $attestationsRead ? $attestations : null,
            );
        }

        return $proof;
    }

    /**
     * @param  list<InvoiceAttestationRecord>  $attestations
     */
    public function evaluatePurchaseInvoice(
        PurchaseInvoice $invoice,
        ?InvoiceSnapshot $snapshot,
        ?CompanyProfile $company = null,
        InvoiceOnChainRecord|string|null $onChain = null,
        bool $chainEnabled = false,
        array $attestations = [],
        ?string $disputeReason = null,
    ): InvoiceProofVerificationData {
        if ($snapshot === null) {
            return InvoiceProofVerificationData::notRegistered();
        }

        $onChainRecord = $onChain instanceof InvoiceOnChainRecord ? $onChain : null;
        $onChainContentHash = $onChainRecord?->contentHash
            ?? (is_string($onChain) ? $onChain : null);

        $snapshotHash = self::snapshotContentHash($snapshot->canonical_json, $snapshot);
        $snapshotIntact = $snapshotHash !== null && hash_equals($snapshot->content_hash, $snapshotHash);
        $liveInvoiceMatches = $this->livePurchaseInvoiceMatches($invoice, $snapshot, $company);

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
            $status = self::statusFromChain($onChainRecord);
        }

        $supplierWallet = WalletAddress::nonZeroOrNull($onChainRecord?->supplierAddress);
        $buyerWallet = WalletAddress::nonZeroOrNull($onChainRecord?->buyerAddress);

        $canApproveAsCompany = false;
        $canApproveAsBuyer = false;
        $canDisputeAsBuyer = false;
        $chainId = $chainEnabled ? (int) config('blockchain.chain_id') : null;
        $contractAddress = $chainEnabled ? $this->configuredContractAddress() : null;
        $eip712 = null;
        $disputeEip712 = null;
        $posted = $invoice->status === PurchaseInvoiceStatus::Posted;
        if ($posted && $status === InvoiceProofVerificationStatus::WaitingCompany && $supplierWallet !== null) {
            $eip712 = $this->partyApprovalTypedData('SupplierApproval', $snapshot, $chainId, $contractAddress);
            $canApproveAsCompany = $eip712 !== null;
        } elseif ($posted && $status === InvoiceProofVerificationStatus::WaitingBuyer && $buyerWallet !== null) {
            $eip712 = $this->partyApprovalTypedData('BuyerApproval', $snapshot, $chainId, $contractAddress);
            $canApproveAsBuyer = $eip712 !== null;
            $disputeEip712 = $this->buyerDisputeTypedData($snapshot, $chainId, $contractAddress);
            $canDisputeAsBuyer = $disputeEip712 !== null;
        }

        return new InvoiceProofVerificationData(
            status: $status,
            snapshotIntact: $snapshotIntact,
            liveInvoiceMatches: $liveInvoiceMatches,
            chainMatches: $chainMatches,
            chainId: $chainId,
            contractAddress: $contractAddress,
            supplierWallet: $supplierWallet,
            buyerWallet: $buyerWallet,
            proofId: (string) $snapshot->id,
            eip712: $eip712,
            disputeEip712: $disputeEip712,
            canApproveAsCompany: $canApproveAsCompany,
            canApproveAsBuyer: $canApproveAsBuyer,
            canDisputeAsBuyer: $canDisputeAsBuyer,
            blockchainNetwork: $chainEnabled ? BlockchainNetwork::key() : null,
            safeTxServiceUrl: $chainEnabled ? BlockchainNetwork::safeTxServiceUrl() : null,
            safeApiKey: $chainEnabled ? BlockchainNetwork::safeApiKey() : null,
            registeredAt: self::chainInstant($onChainRecord?->registeredAt),
            supplierApprovedAt: self::chainInstant($onChainRecord?->supplierApprovedAt),
            buyerApprovedAt: self::chainInstant($onChainRecord?->buyerApprovedAt),
            revokedAt: self::chainInstant($onChainRecord?->revokedAt),
            disputedAt: self::chainInstant($onChainRecord?->disputedAt),
            disputeReasonHash: $onChainRecord?->disputeReasonHash,
            disputeReason: $disputeReason,
            replacedBy: $onChainRecord?->replacedBy,
            attestations: $onChainRecord !== null ? $attestations : [],
            supplierWalletType: self::declaredWalletType(
                $supplierWallet,
                $invoice->supplier?->wallet_address,
                $invoice->supplier?->wallet_type,
            ),
            buyerWalletType: self::declaredWalletType($buyerWallet, $company?->wallet_address, $company?->wallet_type),
        );
    }

    private function verifyLinkedPurchaseInvoice(PurchaseInvoice $invoice, CompanyProfile $company): InvoiceProofVerificationData
    {
        $proofId = (string) $invoice->linked_proof_id;
        $chainEnabled = $this->invoiceChainRegistrationService->isConfigured();
        $onChain = null;
        $attestations = [];
        if ($chainEnabled) {
            try {
                $onChain = $this->invoiceRegistryGateway->invoiceOf($proofId);
            } catch (Throwable) {
                $onChain = null;
            }
        }
        if ($onChain !== null) {
            try {
                $attestations = $this->withVerifierNames($this->invoiceRegistryGateway->attestationsOf($proofId));
            } catch (Throwable) {
                $attestations = [];
            }
        }

        $status = $onChain === null
            ? InvoiceProofVerificationStatus::NotRegistered
            : self::statusFromChain($onChain);
        $supplierWallet = WalletAddress::nonZeroOrNull($onChain?->supplierAddress);
        $buyerWallet = WalletAddress::nonZeroOrNull($onChain?->buyerAddress);
        $chainId = $chainEnabled ? (int) config('blockchain.chain_id') : null;
        $contractAddress = $chainEnabled ? $this->configuredContractAddress() : null;
        $companyWallet = WalletAddress::normalize($company->wallet_address);
        $buyerIsCompany = $buyerWallet !== null && $companyWallet !== null && $buyerWallet === $companyWallet;
        $posted = $invoice->status === PurchaseInvoiceStatus::Posted;
        $eip712 = null;
        $disputeEip712 = null;
        $canApproveAsBuyer = false;
        $canDisputeAsBuyer = false;
        if ($posted && $buyerIsCompany && $onChain !== null && $status === InvoiceProofVerificationStatus::WaitingBuyer) {
            $details = $this->linkedApprovalDetails($invoice);
            if ($chainId !== null && $chainId > 0 && $contractAddress !== null && $details !== null) {
                try {
                    $eip712 = InvoiceRegistryAbi::buyerApprovalTypedData(
                        $chainId,
                        $contractAddress,
                        $proofId,
                        $onChain->contentHash,
                        $details['invoice_number'],
                        $details['statement'],
                    );
                    $disputeEip712 = InvoiceRegistryAbi::buyerDisputeTypedData(
                        $chainId,
                        $contractAddress,
                        $proofId,
                        $onChain->contentHash,
                        $details['invoice_number'],
                        InvoiceApprovalStatement::dispute($details['supplier_name'], $details['invoice_number']),
                    );
                    $canApproveAsBuyer = true;
                    $canDisputeAsBuyer = true;
                } catch (InvalidArgumentException) {
                    $eip712 = null;
                    $disputeEip712 = null;
                }
            }
        }

        return new InvoiceProofVerificationData(
            status: $status,
            snapshotIntact: $onChain !== null,
            liveInvoiceMatches: null,
            chainMatches: $onChain !== null,
            chainId: $chainId,
            contractAddress: $contractAddress,
            supplierWallet: $supplierWallet,
            buyerWallet: $buyerWallet,
            proofId: $proofId,
            eip712: $eip712,
            disputeEip712: $disputeEip712,
            canApproveAsCompany: false,
            canApproveAsBuyer: $canApproveAsBuyer,
            canDisputeAsBuyer: $canDisputeAsBuyer,
            blockchainNetwork: $chainEnabled ? BlockchainNetwork::key() : null,
            safeTxServiceUrl: $chainEnabled ? BlockchainNetwork::safeTxServiceUrl() : null,
            safeApiKey: $chainEnabled ? BlockchainNetwork::safeApiKey() : null,
            registeredAt: self::chainInstant($onChain?->registeredAt),
            supplierApprovedAt: self::chainInstant($onChain?->supplierApprovedAt),
            buyerApprovedAt: self::chainInstant($onChain?->buyerApprovedAt),
            revokedAt: self::chainInstant($onChain?->revokedAt),
            disputedAt: self::chainInstant($onChain?->disputedAt),
            disputeReasonHash: $onChain?->disputeReasonHash,
            disputeReason: is_string($invoice->linked_dispute_reason) && $invoice->linked_dispute_reason !== ''
                ? $invoice->linked_dispute_reason
                : null,
            replacedBy: $onChain?->replacedBy,
            attestations: $onChain !== null ? $attestations : [],
            supplierWalletType: self::declaredWalletType(
                $supplierWallet,
                $invoice->supplier?->wallet_address,
                $invoice->supplier?->wallet_type,
            ),
            buyerWalletType: self::declaredWalletType($buyerWallet, $company->wallet_address, $company->wallet_type),
        );
    }

    /**
     * @return array{invoice_number: string, statement: string, supplier_name: string}|null
     */
    private function linkedApprovalDetails(PurchaseInvoice $invoice): ?array
    {
        $invoiceNumber = trim((string) $invoice->invoice_number);
        if ($invoiceNumber === '') {
            return null;
        }
        $supplierName = trim((string) ($invoice->supplier?->name ?? ''));
        $currencyCode = trim((string) ($invoice->currency?->code ?? ''));

        return [
            'invoice_number' => $invoiceNumber,
            'supplier_name' => $supplierName,
            'statement' => InvoiceApprovalStatement::approve(
                $supplierName,
                $invoiceNumber,
                (string) $invoice->grand_total,
                $currencyCode,
            ),
        ];
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
        ?string $disputeReason = null,
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
            $status = self::statusFromChain($onChainRecord);
        }

        $supplierWallet = WalletAddress::nonZeroOrNull($onChainRecord?->supplierAddress);
        $buyerWallet = WalletAddress::nonZeroOrNull($onChainRecord?->buyerAddress);

        $canApproveAsCompany = false;
        $canApproveAsBuyer = false;
        $canDisputeAsBuyer = false;
        $chainId = $chainEnabled ? (int) config('blockchain.chain_id') : null;
        $contractAddress = $chainEnabled ? $this->configuredContractAddress() : null;
        $eip712 = null;
        $disputeEip712 = null;
        // A reversed invoice is revoked by a queued job; until it lands nobody may approve or dispute it.
        $posted = $invoice->status === SalesInvoiceStatus::Posted;
        if ($posted && $status === InvoiceProofVerificationStatus::WaitingCompany && $supplierWallet !== null) {
            $eip712 = $this->partyApprovalTypedData('SupplierApproval', $snapshot, $chainId, $contractAddress);
            $canApproveAsCompany = $eip712 !== null;
        } elseif ($posted && $status === InvoiceProofVerificationStatus::WaitingBuyer && $buyerWallet !== null) {
            $eip712 = $this->partyApprovalTypedData('BuyerApproval', $snapshot, $chainId, $contractAddress);
            $canApproveAsBuyer = $eip712 !== null;
            $disputeEip712 = $this->buyerDisputeTypedData($snapshot, $chainId, $contractAddress);
            $canDisputeAsBuyer = $disputeEip712 !== null;
        }

        return new InvoiceProofVerificationData(
            status: $status,
            snapshotIntact: $snapshotIntact,
            liveInvoiceMatches: $liveInvoiceMatches,
            chainMatches: $chainMatches,
            chainId: $chainId,
            contractAddress: $contractAddress,
            supplierWallet: $supplierWallet,
            buyerWallet: $buyerWallet,
            proofId: (string) $snapshot->id,
            eip712: $eip712,
            disputeEip712: $disputeEip712,
            canApproveAsCompany: $canApproveAsCompany,
            canApproveAsBuyer: $canApproveAsBuyer,
            canDisputeAsBuyer: $canDisputeAsBuyer,
            blockchainNetwork: $chainEnabled ? BlockchainNetwork::key() : null,
            safeTxServiceUrl: $chainEnabled ? BlockchainNetwork::safeTxServiceUrl() : null,
            safeApiKey: $chainEnabled ? BlockchainNetwork::safeApiKey() : null,
            registeredAt: self::chainInstant($onChainRecord?->registeredAt),
            supplierApprovedAt: self::chainInstant($onChainRecord?->supplierApprovedAt),
            buyerApprovedAt: self::chainInstant($onChainRecord?->buyerApprovedAt),
            revokedAt: self::chainInstant($onChainRecord?->revokedAt),
            disputedAt: self::chainInstant($onChainRecord?->disputedAt),
            disputeReasonHash: $onChainRecord?->disputeReasonHash,
            disputeReason: $disputeReason,
            replacedBy: $onChainRecord?->replacedBy,
            attestations: $onChainRecord !== null ? $attestations : [],
            supplierWalletType: self::declaredWalletType($supplierWallet, $company?->wallet_address, $company?->wallet_type),
            buyerWalletType: self::declaredWalletType(
                $buyerWallet,
                $invoice->customer?->wallet_address,
                $invoice->customer?->wallet_type,
            ),
        );
    }

    /**
     * The ERP-declared type, only when the on-chain party is still the stored address.
     */
    private static function declaredWalletType(?string $onChain, ?string $stored, ?WalletType $type): ?string
    {
        if ($onChain === null || $type === null || WalletAddress::normalize($stored) !== $onChain) {
            return null;
        }

        return $type->value;
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

    private function livePurchaseInvoiceMatches(
        PurchaseInvoice $invoice,
        InvoiceSnapshot $snapshot,
        ?CompanyProfile $company,
    ): ?bool {
        try {
            $liveJson = PurchaseInvoiceCanonicalSerializer::serialize(
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
     * @return array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array<string, list<array{name: string, type: string}>>,
     *     message: array{proof_id: string, content_hash: string, invoice_number: string, statement: string}
     * }|null
     */
    private function buyerDisputeTypedData(
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

        $canonical = json_decode($snapshot->canonical_json, true);
        $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
        $companyName = is_string($supplier['name'] ?? null) ? $supplier['name'] : '';

        try {
            return InvoiceRegistryAbi::buyerDisputeTypedData(
                $chainId,
                $contractAddress,
                (string) $snapshot->id,
                $snapshot->content_hash,
                $details['invoice_number'],
                InvoiceApprovalStatement::dispute($companyName, $details['invoice_number']),
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

    public static function statusFromChain(?InvoiceOnChainRecord $onChain): InvoiceProofVerificationStatus
    {
        if ($onChain === null) {
            return InvoiceProofVerificationStatus::Verified;
        }

        // An empty party slot still waits for that party: setParties fills it once the wallet is saved.
        return match ($onChain->status) {
            InvoiceOnChainStatus::Registered => InvoiceProofVerificationStatus::WaitingCompany,
            InvoiceOnChainStatus::SupplierApproved => InvoiceProofVerificationStatus::WaitingBuyer,
            InvoiceOnChainStatus::FullyApproved => InvoiceProofVerificationStatus::FullyApproved,
            InvoiceOnChainStatus::Revoked => InvoiceProofVerificationStatus::Revoked,
            InvoiceOnChainStatus::Disputed => InvoiceProofVerificationStatus::Disputed,
        };
    }

    /**
     * Plaintext dispute reason for a proof. The chain stores only a hash.
     * A buyer may have saved the text on a linked purchase invoice in this tenant or another.
     */
    private function disputeReasonForProof(string $proofId, ?string $onChainHash, bool $searchOtherTenants): ?string
    {
        $local = $this->linkedOrStoredDisputeReason($proofId, $onChainHash);
        if ($local !== null || ! $searchOtherTenants || ! is_string($onChainHash) || $onChainHash === '') {
            return $local;
        }

        $currentId = tenant('id');
        $found = null;
        Tenant::query()
            ->when(is_string($currentId) && $currentId !== '', fn ($query) => $query->where('id', '!=', $currentId))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($proofId, $onChainHash, &$found): bool {
                $reason = $tenant->run(fn (): ?string => $this->linkedPurchaseDisputeReason($proofId, $onChainHash));
                if (! is_string($reason) || $reason === '') {
                    return true;
                }
                $found = $reason;

                return false;
            });

        if ($found === null) {
            return null;
        }

        InvoiceChainRegistration::query()
            ->where('proof_id', $proofId)
            ->where(function ($query): void {
                $query->whereNull('dispute_reason')->orWhere('dispute_reason', '');
            })
            ->update([
                'dispute_reason' => $found,
                'dispute_reason_hash' => strtolower($onChainHash),
            ]);

        return $found;
    }

    private function linkedOrStoredDisputeReason(string $proofId, ?string $onChainHash): ?string
    {
        $stored = InvoiceChainRegistration::query()->where('proof_id', $proofId)->value('dispute_reason');
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        return $this->linkedPurchaseDisputeReason($proofId, $onChainHash);
    }

    private function linkedPurchaseDisputeReason(string $proofId, ?string $onChainHash): ?string
    {
        $linked = PurchaseInvoice::query()
            ->where('linked_proof_id', $proofId)
            ->whereNotNull('linked_dispute_reason')
            ->orderByDesc('updated_at')
            ->value('linked_dispute_reason');
        if (! is_string($linked) || trim($linked) === '') {
            return null;
        }

        $linked = trim($linked);
        if (! is_string($onChainHash) || $onChainHash === '') {
            return $linked;
        }

        return hash_equals(strtolower($onChainHash), strtolower(InvoiceProofBytes::keccakUtf8($linked)))
            ? $linked
            : null;
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
