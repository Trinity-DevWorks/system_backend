<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
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
        [$snapshot, $tree] = $this->sealedTree($invoice);

        return InvoiceProofFieldsData::fromTree($snapshot, $tree);
    }

    /**
     * @param  list<string>  $paths
     */
    public function disclose(SalesInvoice $invoice, array $paths): InvoiceProofDisclosureData
    {
        [$snapshot, $tree] = $this->sealedTree($invoice);

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
        $registration = $chainEnabled
            ? $this->invoiceChainRegistrationService->findForSalesInvoice((string) $invoice->id)
            : null;
        if ($registration?->status !== InvoiceChainRegistrationStatus::Confirmed) {
            $registration = null;
        }

        return InvoiceProofDisclosureData::fromTree(
            $snapshot,
            $tree,
            array_values($indices),
            $chainEnabled ? (int) config('blockchain.chain_id') : null,
            $chainEnabled ? $this->configuredContractAddress() : null,
            $registration,
        );
    }

    /**
     * @return array{0: InvoiceSnapshot, 1: CanonicalInvoiceMerkle}
     */
    private function sealedTree(SalesInvoice $invoice): array
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(403, 'Invoice proofs are disabled for this company.', [
                'X-Error-Code' => 'INVOICE_PROOFS_DISABLED',
            ]);
        }

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
