<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Serializers\SalesInvoiceCanonicalSerializer;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Support\Str;

/**
 * Builds canonical JSON for a posted invoice, stores it with a fresh disclosure
 * secret, and records its salted Merkle root as the content hash.
 */
class InvoiceSnapshotService
{
    public function captureSalesInvoice(SalesInvoice $invoice): InvoiceSnapshot
    {
        if ($this->exists(InvoiceProofType::Sales, (string) $invoice->id)) {
            abort(409, 'This invoice already has an immutable snapshot.', ['X-Error-Code' => 'INVOICE_SNAPSHOT_ALREADY_EXISTS']);
        }

        $proofId = (string) Str::uuid();
        $canonicalJson = SalesInvoiceCanonicalSerializer::serialize($invoice, $proofId)->toJson();
        $disclosureSecret = CanonicalInvoiceMerkle::newSecret();

        return InvoiceSnapshot::query()->create([
            'id' => $proofId,
            'invoice_type' => InvoiceProofType::Sales,
            'invoice_id' => $invoice->id,
            'schema_version' => CanonicalInvoiceSchema::VERSION,
            'canonical_json' => $canonicalJson,
            'content_hash' => CanonicalInvoiceHasher::hash($canonicalJson, $disclosureSecret),
            'disclosure_secret' => $disclosureSecret,
        ]);
    }

    public function findForSalesInvoice(string $invoiceId): ?InvoiceSnapshot
    {
        return InvoiceSnapshot::query()
            ->where('invoice_type', InvoiceProofType::Sales)
            ->where('invoice_id', $invoiceId)
            ->first();
    }

    public function exists(InvoiceProofType $invoiceType, string $invoiceId): bool
    {
        return InvoiceSnapshot::query()
            ->where('invoice_type', $invoiceType)
            ->where('invoice_id', $invoiceId)
            ->exists();
    }
}
