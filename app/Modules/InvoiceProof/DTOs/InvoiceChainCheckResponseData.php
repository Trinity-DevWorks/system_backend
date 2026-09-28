<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Models\InvoiceChainCheck;
use App\Modules\InvoiceProof\Models\InvoiceChainCheckIssue;

readonly class InvoiceChainCheckResponseData
{
    /**
     * @param  list<array<string, string|null>>  $issues
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $blockchainNetwork,
        public string $contractAddress,
        public int $checkedCount,
        public int $issueCount,
        public int $requeuedCount,
        public ?int $scannedFromBlock,
        public ?int $scannedToBlock,
        public ?string $error,
        public string $startedAt,
        public string $finishedAt,
        public array $issues,
    ) {}

    public static function fromModel(InvoiceChainCheck $check): self
    {
        $check->loadMissing('issues');

        return new self(
            id: (string) $check->id,
            status: $check->status->value,
            blockchainNetwork: (string) $check->blockchain_network,
            contractAddress: (string) $check->contract_address,
            checkedCount: (int) $check->checked_count,
            issueCount: (int) $check->issue_count,
            requeuedCount: (int) $check->requeued_count,
            scannedFromBlock: $check->scanned_from_block,
            scannedToBlock: $check->scanned_to_block,
            error: $check->error,
            startedAt: $check->started_at->toIso8601String(),
            finishedAt: $check->finished_at->toIso8601String(),
            issues: $check->issues
                ->sortBy('created_at')
                ->map(static fn (InvoiceChainCheckIssue $issue): array => [
                    'id' => (string) $issue->id,
                    'kind' => $issue->kind->value,
                    'proof_id' => $issue->proof_id,
                    'chain_proof_id' => $issue->chain_proof_id,
                    'invoice_id' => $issue->invoice_id,
                    'invoice_number' => $issue->invoice_number,
                    'expected' => $issue->expected,
                    'actual' => $issue->actual,
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'blockchain_network' => $this->blockchainNetwork,
            'contract_address' => $this->contractAddress,
            'checked_count' => $this->checkedCount,
            'issue_count' => $this->issueCount,
            'requeued_count' => $this->requeuedCount,
            'scanned_from_block' => $this->scannedFromBlock,
            'scanned_to_block' => $this->scannedToBlock,
            'error' => $this->error,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'issues' => $this->issues,
        ];
    }
}
