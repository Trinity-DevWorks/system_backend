<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;

/**
 * Verification payload. Status is the snapshot hash versus the chain hash.
 * live_invoice_matches is diagnostic and does not change status.
 */
readonly class InvoiceProofVerificationData
{
    /**
     * @param  array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array<string, list<array{name: string, type: string}>>,
     *     message: array{proof_id: string, content_hash: string, invoice_number: string}
     * }|null  $eip712
     * @param  list<InvoiceAttestationRecord>  $attestations
     */
    public function __construct(
        public InvoiceProofVerificationStatus $status,
        public ?bool $snapshotIntact,
        public ?bool $liveInvoiceMatches,
        public ?bool $chainMatches,
        public ?int $chainId = null,
        public ?string $contractAddress = null,
        public ?string $supplierWallet = null,
        public ?string $buyerWallet = null,
        public ?string $proofId = null,
        public ?array $eip712 = null,
        public ?array $disputeEip712 = null,
        public bool $canApproveAsCompany = false,
        public bool $canApproveAsBuyer = false,
        public bool $canDisputeAsBuyer = false,
        public bool $canDisputeAsSupplier = false,
        public ?string $blockchainNetwork = null,
        public ?string $safeTxServiceUrl = null,
        public ?string $safeApiKey = null,
        public ?string $registeredAt = null,
        public ?string $supplierApprovedAt = null,
        public ?string $buyerApprovedAt = null,
        public array $attestations = [],
        public ?string $supplierWalletType = null,
        public ?string $buyerWalletType = null,
        public ?string $revokedAt = null,
        public ?string $disputedAt = null,
        public ?string $disputeReasonHash = null,
        public ?string $disputeReason = null,
        public ?string $replacedBy = null,
        public ?string $tamperReason = null,
        /** @var list<string> */
        public array $tamperedFields = [],
    ) {}

    /**
     * The contract allows at most one financier attestation per proof.
     */
    public function financedBy(): ?string
    {
        foreach ($this->attestations as $attestation) {
            if ($attestation->role === InvoiceVerifierRole::Financier && $attestation->partySide !== 'buyer') {
                return $attestation->verifier;
            }
        }

        return null;
    }

    /**
     * ISO 8601 time of the financier attestation, or null while the invoice is not financed.
     */
    public function financedAt(): ?string
    {
        foreach ($this->attestations as $attestation) {
            if ($attestation->role === InvoiceVerifierRole::Financier && $attestation->partySide !== 'buyer') {
                return $attestation->toArray()['attested_at'];
            }
        }

        return null;
    }

    public static function notRegistered(): self
    {
        return new self(
            status: InvoiceProofVerificationStatus::NotRegistered,
            snapshotIntact: null,
            liveInvoiceMatches: null,
            chainMatches: null,
        );
    }

    /**
     * @return array{
     *     status: string,
     *     snapshot_intact: ?bool,
     *     live_invoice_matches: ?bool,
     *     chain_matches: ?bool,
     *     chain_id: ?int,
     *     contract_address: ?string,
     *     supplier_wallet: ?string,
     *     supplier_wallet_type: ?string,
     *     buyer_wallet: ?string,
     *     buyer_wallet_type: ?string,
     *     proof_id: ?string,
     *     eip712: ?array<string, mixed>,
     *     can_approve_as_company: bool,
     *     can_approve_as_buyer: bool,
     *     blockchain_network: ?string,
     *     safe_tx_service_url: ?string,
     *     safe_api_key: ?string,
     *     registered_at: ?string,
     *     supplier_approved_at: ?string,
     *     buyer_approved_at: ?string,
     *     revoked_at: ?string,
     *     attestations: list<array{verifier: string, verifier_name: ?string, role: string, reference_hash: ?string, attested_at: ?string}>,
     *     financed_by: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'snapshot_intact' => $this->snapshotIntact,
            'live_invoice_matches' => $this->liveInvoiceMatches,
            'chain_matches' => $this->chainMatches,
            'chain_id' => $this->chainId,
            'contract_address' => $this->contractAddress,
            'supplier_wallet' => $this->supplierWallet,
            'supplier_wallet_type' => $this->supplierWalletType,
            'buyer_wallet' => $this->buyerWallet,
            'buyer_wallet_type' => $this->buyerWalletType,
            'proof_id' => $this->proofId,
            'eip712' => $this->eip712,
            'dispute_eip712' => $this->disputeEip712,
            'can_approve_as_company' => $this->canApproveAsCompany,
            'can_approve_as_buyer' => $this->canApproveAsBuyer,
            'can_dispute_as_buyer' => $this->canDisputeAsBuyer,
            'can_dispute_as_supplier' => $this->canDisputeAsSupplier,
            'blockchain_network' => $this->blockchainNetwork,
            'safe_tx_service_url' => $this->safeTxServiceUrl,
            'safe_api_key' => $this->safeApiKey,
            'registered_at' => $this->registeredAt,
            'supplier_approved_at' => $this->supplierApprovedAt,
            'buyer_approved_at' => $this->buyerApprovedAt,
            'revoked_at' => $this->revokedAt,
            'disputed_at' => $this->disputedAt,
            'dispute_reason_hash' => $this->disputeReasonHash,
            'dispute_reason' => $this->disputeReason,
            'replaced_by' => $this->replacedBy,
            'tamper_reason' => $this->tamperReason,
            'tampered_fields' => $this->tamperedFields,
            'attestations' => array_map(
                static fn (InvoiceAttestationRecord $attestation): array => $attestation->toArray(),
                $this->attestations,
            ),
            'financed_by' => $this->financedBy(),
        ];
    }
}
