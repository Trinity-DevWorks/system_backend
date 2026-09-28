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
     * Registrar lists (role > 0) or removes (role 0) a verifier for one company Safe.
     */
    public function setVerifier(string $companyAddress, string $verifierAddress, int $role): InvoiceChainReceiptData;

    /**
     * Third-party attestations in on-chain order.
     *
     * @return list<InvoiceAttestationRecord>
     */
    public function attestationsOf(string $proofId): array;
}
