<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

readonly class InvoiceChainReceiptData
{
    public function __construct(
        public string $txHash,
        public ?int $blockNumber,
        public string $contractAddress,
    ) {}
}
