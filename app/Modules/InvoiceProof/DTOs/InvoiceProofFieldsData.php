<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;

/**
 * Every disclosable leaf of a sealed snapshot, for picking what to share.
 */
readonly class InvoiceProofFieldsData
{
    /**
     * @param  list<array{path: string, value: string|int|bool|array{}|null}>  $fields
     */
    public function __construct(
        public string $proofId,
        public string $contentHash,
        public int $schemaVersion,
        public array $fields,
    ) {}

    public static function fromTree(InvoiceSnapshot $snapshot, CanonicalInvoiceMerkle $tree): self
    {
        return new self(
            proofId: (string) $snapshot->id,
            contentHash: $snapshot->content_hash,
            schemaVersion: (int) $snapshot->schema_version,
            fields: $tree->leaves(),
        );
    }

    /**
     * @return array{
     *     proof_id: string,
     *     content_hash: string,
     *     schema_version: int,
     *     leaf_count: int,
     *     fields: list<array{path: string, value: string|int|bool|array{}|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'proof_id' => $this->proofId,
            'content_hash' => $this->contentHash,
            'schema_version' => $this->schemaVersion,
            'leaf_count' => count($this->fields),
            'fields' => $this->fields,
        ];
    }
}
