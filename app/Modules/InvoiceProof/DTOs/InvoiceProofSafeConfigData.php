<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

/**
 * Public Safe Transaction Service settings for the verifier page.
 * Same fields clerks already receive on invoice verify. Not a proof-file field.
 */
readonly class InvoiceProofSafeConfigData
{
    public function __construct(
        public ?int $chainId,
        public ?string $safeTxServiceUrl,
        public ?string $safeApiKey,
    ) {}

    /**
     * @return array{
     *     chain_id: ?int,
     *     safe_tx_service_url: ?string,
     *     safe_api_key: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'chain_id' => $this->chainId,
            'safe_tx_service_url' => $this->safeTxServiceUrl,
            'safe_api_key' => $this->safeApiKey,
        ];
    }
}
