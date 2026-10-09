<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceChainReceiptData;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use RuntimeException;

/**
 * Used when blockchain is disabled. Register and approve must never run.
 */
final class DisabledInvoiceRegistryGateway implements InvoiceRegistryGateway
{
    public function contentHashOf(string $proofId): ?string
    {
        return null;
    }

    public function invoiceOf(string $proofId): ?InvoiceOnChainRecord
    {
        return null;
    }

    public function registerInvoice(
        string $proofId,
        string $contentHash,
        string $supplierAddress,
        string $buyerAddress,
    ): InvoiceChainReceiptData {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function setParties(
        string $proofId,
        string $supplierAddress,
        string $buyerAddress,
    ): InvoiceChainReceiptData {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function approveBySupplier(string $proofId, string $supplierAddress): InvoiceChainReceiptData
    {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function revokeInvoice(string $proofId, ?string $replacementProofId): InvoiceChainReceiptData
    {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function sealBrokenAt(string $proofId): ?int
    {
        return null;
    }

    public function markSealBroken(string $proofId): InvoiceChainReceiptData
    {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function restoreSeal(string $proofId): InvoiceChainReceiptData
    {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function setReplacement(string $proofId, string $replacementProofId): InvoiceChainReceiptData
    {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function setVerifier(string $companyAddress, string $verifierAddress, int $role, int $partySide): InvoiceChainReceiptData
    {
        throw new RuntimeException('Blockchain registration is disabled.');
    }

    public function attestationsOf(string $proofId): array
    {
        return [];
    }

    public function latestBlockNumber(): int
    {
        return 0;
    }

    public function registeredBySupplier(string $supplierAddress, int $fromBlock, int $toBlock): array
    {
        return [];
    }
}
