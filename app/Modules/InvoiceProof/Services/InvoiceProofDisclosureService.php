<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceRules;
use App\Modules\InvoiceProof\DTOs\InvoiceProofDisclosureData;
use App\Modules\InvoiceProof\DTOs\InvoiceProofFieldsData;
use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use InvalidArgumentException;

/**
 * Selective disclosure of sealed snapshot fields. Builds Merkle proofs against
 * the stored content hash; refuses when the snapshot no longer matches it.
 */
class InvoiceProofDisclosureService
{
    public function __construct(
        private readonly InvoiceSnapshotService $invoiceSnapshotService,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
    ) {}

    public function fields(SalesInvoice $invoice): InvoiceProofFieldsData
    {
        $this->assertRegisteredOnChain(
            $this->invoiceChainRegistrationService->findForSalesInvoice((string) $invoice->id),
        );
        [$snapshot, $tree] = $this->sealedTree($this->salesSnapshot($invoice));

        return InvoiceProofFieldsData::fromTree($snapshot, $tree);
    }

    public function fieldsForPurchase(PurchaseInvoice $invoice): InvoiceProofFieldsData
    {
        $this->assertRegisteredOnChain(
            $this->invoiceChainRegistrationService->findForPurchaseInvoice((string) $invoice->id),
        );
        [$snapshot, $tree] = $this->sealedTree($this->purchaseSnapshot($invoice));

        return InvoiceProofFieldsData::fromTree($snapshot, $tree);
    }

    /**
     * @return array<string, mixed>
     */
    public function discloseAll(SalesInvoice $invoice): array
    {
        $paths = array_column($this->fields($invoice)->toArray()['fields'], 'path');

        return $this->disclose($invoice, $paths)->toArray();
    }

    public function disclose(SalesInvoice $invoice, array $paths): InvoiceProofDisclosureData
    {
        $registration = $this->invoiceChainRegistrationService->findForSalesInvoice((string) $invoice->id);
        $this->assertRegisteredOnChain($registration);

        return $this->discloseSnapshot($this->salesSnapshot($invoice), $paths, $registration);
    }

    /**
     * @param  list<string>  $paths
     */
    public function disclosePurchase(PurchaseInvoice $invoice, array $paths): InvoiceProofDisclosureData
    {
        $registration = $this->invoiceChainRegistrationService->findForPurchaseInvoice((string) $invoice->id);
        $this->assertRegisteredOnChain($registration);

        return $this->discloseSnapshot($this->purchaseSnapshot($invoice), $paths, $registration);
    }

    private function assertRegisteredOnChain(mixed $registration): void
    {
        if (! $this->invoiceChainRegistrationService->isConfigured()) {
            return;
        }

        if ($registration?->status !== InvoiceChainRegistrationStatus::Confirmed) {
            abort(422, 'This invoice is not registered on chain yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_ON_CHAIN',
            ]);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function discloseSnapshot(InvoiceSnapshot $snapshot, array $paths, mixed $registration): InvoiceProofDisclosureData
    {
        [$snapshot, $tree] = $this->sealedTree($snapshot);

        $indices = [];
        foreach ($paths as $path) {
            $index = $tree->indexOf($path);
            if ($index === null) {
                abort(422, 'This invoice proof has no field "'.$path.'".', [
                    'X-Error-Code' => 'INVOICE_PROOF_FIELD_UNKNOWN',
                ]);
            }
            $indices[$index] = $index;
        }
        ksort($indices);

        $chainEnabled = $this->invoiceChainRegistrationService->isConfigured();
        if ($registration?->status !== InvoiceChainRegistrationStatus::Confirmed) {
            $registration = null;
        }

        return InvoiceProofDisclosureData::fromTree(
            $snapshot,
            $tree,
            array_values($indices),
            $chainEnabled ? (int) config('blockchain.chain_id') : null,
            $chainEnabled ? $this->configuredContractAddress() : null,
            $chainEnabled ? $registration : null,
        );
    }

    private function salesSnapshot(SalesInvoice $invoice): InvoiceSnapshot
    {
        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            abort(422, 'Only posted sales invoices have a proof.', [
                'X-Error-Code' => 'SALES_INVOICE_NOT_POSTED',
            ]);
        }

        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(422, 'This invoice has no sealed proof yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_REGISTERED',
            ]);
        }

        return $snapshot;
    }

    private function purchaseSnapshot(PurchaseInvoice $invoice): InvoiceSnapshot
    {
        PurchaseInvoiceRules::assertPostedForProof($invoice);

        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(422, 'This invoice has no sealed proof yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_REGISTERED',
            ]);
        }

        return $snapshot;
    }

    /**
     * @return array{0: InvoiceSnapshot, 1: CanonicalInvoiceMerkle}
     */
    private function sealedTree(InvoiceSnapshot $snapshot): array
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(403, 'Invoice proofs are disabled for this company.', [
                'X-Error-Code' => 'INVOICE_PROOFS_DISABLED',
            ]);
        }

        try {
            $tree = CanonicalInvoiceMerkle::build($snapshot->canonical_json, (string) $snapshot->disclosure_secret);
        } catch (InvalidArgumentException) {
            $tree = null;
        }

        if ($tree === null || ! hash_equals($snapshot->content_hash, $tree->root())) {
            abort(422, 'Invoice proof does not match. Disclosure is blocked.', [
                'X-Error-Code' => 'INVOICE_PROOF_TAMPERED',
            ]);
        }

        return [$snapshot, $tree];
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
