<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Contracts;

use App\Modules\InvoiceProof\DTOs\InvoiceAttestationRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceChainReceiptData;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;

interface InvoiceRegistryGateway
{
    public function contentHashOf(string $proofId): ?string;

    public function invoiceOf(string $proofId): ?InvoiceOnChainRecord;

    public function registerInvoice(
        string $proofId,
        string $contentHash,
        string $supplierAddress,
        string $buyerAddress,
    ): InvoiceChainReceiptData;

    public function setParties(
        string $proofId,
        string $supplierAddress,
        string $buyerAddress,
    ): InvoiceChainReceiptData;

    public function approveBySupplier(string $proofId, string $supplierAddress): InvoiceChainReceiptData;

    /**
     * Registrar cancels a sealed invoice, optionally naming the proof that replaces it.
     * Succeeds without a transaction when the proof is already revoked.
     */
    public function revokeInvoice(string $proofId, ?string $replacementProofId): InvoiceChainReceiptData;

    /**
     * Unix time the registrar marked this seal broken, or null when it is clear
     * or the chain read failed.
     */
    public function sealBrokenAt(string $proofId): ?int;

    /**
     * Registrar closes attestation because the ERP copy no longer matches the seal.
     * Succeeds without a transaction when the mark is already set.
     */
    public function markSealBroken(string $proofId): InvoiceChainReceiptData;

    /**
     * Registrar reopens attestation after the ERP copy matches the seal again.
     * Succeeds without a transaction when the mark is already clear.
     */
    public function restoreSeal(string $proofId): InvoiceChainReceiptData;

    /**
     * Registrar fills `replacedBy` on an already revoked proof. Succeeds without a
     * transaction when the chain already points at the same successor.
     */
    public function setReplacement(string $proofId, string $replacementProofId): InvoiceChainReceiptData;

    /**
     * Registrar lists (role > 0) or removes (role 0) a verifier for one company and party side.
     * `$partySide` is 0 for the supplier and 1 for the buyer.
     */
    public function setVerifier(string $companyAddress, string $verifierAddress, int $role, int $partySide): InvoiceChainReceiptData;

    /**
     * Third-party attestations in on-chain order.
     *
     * @return list<InvoiceAttestationRecord>
     */
    public function attestationsOf(string $proofId): array;

    public function latestBlockNumber(): int;

    /**
     * `InvoiceRegistered` logs whose indexed supplier is `$supplierAddress`, inclusive block range.
     *
     * @return list<array{proof_id: string, content_hash: string, block_number: int}>
     */
    public function registeredBySupplier(string $supplierAddress, int $fromBlock, int $toBlock): array;
}
