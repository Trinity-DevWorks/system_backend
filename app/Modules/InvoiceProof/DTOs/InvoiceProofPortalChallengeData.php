<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

/**
 * HMAC GET returns this stub only. Commercial fields stay behind personal_sign.
 */
readonly class InvoiceProofPortalChallengeData
{
    public function __construct(
        public bool $locked,
        public int $chainId,
        public ?string $buyerWallet,
        public string $nonce,
        public string $message,
    ) {}

    /**
     * @return array{
     *     locked: true,
     *     chain_id: int,
     *     buyer_wallet: ?string,
     *     nonce: string,
     *     message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'locked' => true,
            'chain_id' => $this->chainId,
            'buyer_wallet' => $this->buyerWallet,
            'nonce' => $this->nonce,
            'message' => $this->message,
        ];
    }
}
