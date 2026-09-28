<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;
use Carbon\Carbon;

/**
 * Self-contained selective-disclosure bundle. A third party recomputes the
 * Merkle root from each field, its salt, and its proof, then compares it with
 * `contentHashOf(proof_id)` on InvoiceRegistry. Undisclosed fields stay hidden.
 */
readonly class InvoiceProofDisclosureData
{
    public const FORMAT = 'invoice-proof-disclosure';

    /**
     * @param  list<array{
     *     path: string,
     *     value: string|int|bool|array{}|null,
     *     index: int,
     *     salt: string,
     *     proof: list<array{position: 'left'|'right', hash: string}>
     * }>  $fields
     */
    public function __construct(
        public int $schemaVersion,
        public string $proofId,
        public string $contentHash,
        public int $leafCount,
        public ?int $chainId,
        public ?string $contractAddress,
        public ?string $txHash,
        public ?int $blockNumber,
        public string $generatedAt,
        public array $fields,
    ) {}

    /**
     * @param  list<int>  $indices
     */
    public static function fromTree(
        InvoiceSnapshot $snapshot,
        CanonicalInvoiceMerkle $tree,
        array $indices,
        ?int $chainId,
        ?string $contractAddress,
        ?InvoiceChainRegistration $registration,
    ): self {
        $leaves = $tree->leaves();
        $fields = [];
        foreach ($indices as $index) {
            $fields[] = [
                'path' => $leaves[$index]['path'],
                'value' => $leaves[$index]['value'],
                'index' => $index,
                'salt' => $tree->saltAt($index),
                'proof' => $tree->proof($index),
            ];
        }

        return new self(
            schemaVersion: (int) $snapshot->schema_version,
            proofId: (string) $snapshot->id,
            contentHash: $snapshot->content_hash,
            leafCount: $tree->leafCount(),
            chainId: $chainId,
            contractAddress: $registration?->contract_address ?? $contractAddress,
            txHash: $registration?->tx_hash,
            blockNumber: $registration?->block_number,
            generatedAt: Carbon::now()->utc()->toIso8601String(),
            fields: $fields,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'schema_version' => $this->schemaVersion,
            'proof_id' => $this->proofId,
            'content_hash' => $this->contentHash,
            'leaf_count' => $this->leafCount,
            'chain_id' => $this->chainId,
            'contract_address' => $this->contractAddress,
            'tx_hash' => $this->txHash,
            'block_number' => $this->blockNumber,
            'generated_at' => $this->generatedAt,
            'fields' => $this->fields,
        ];
    }
}
