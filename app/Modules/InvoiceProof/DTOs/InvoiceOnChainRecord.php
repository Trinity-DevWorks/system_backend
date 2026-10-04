<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;

readonly class InvoiceOnChainRecord
{
    /**
     * @param  string|null  $replacedBy  snapshot UUID of the proof that supersedes this one
     */
    public function __construct(
        public string $contentHash,
        public string $supplierAddress,
        public string $buyerAddress,
        public bool $supplierApproved,
        public bool $buyerApproved,
        public InvoiceOnChainStatus $status,
        public ?int $registeredAt = null,
        public ?int $supplierApprovedAt = null,
        public ?int $buyerApprovedAt = null,
        public ?int $revokedAt = null,
        public ?string $replacedBy = null,
    ) {}
}
