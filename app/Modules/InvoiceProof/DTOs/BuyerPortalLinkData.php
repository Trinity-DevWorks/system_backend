<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

readonly class BuyerPortalLinkData
{
    public function __construct(
        public string $url,
        public int $exp,
        public string $sig,
    ) {}

    /**
     * @param  array{url: string, exp: int, sig: string}  $issued
     */
    public static function fromIssued(array $issued): self
    {
        return new self(
            url: $issued['url'],
            exp: $issued['exp'],
            sig: $issued['sig'],
        );
    }

    /**
     * @return array{url: string, exp: int, sig: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'exp' => $this->exp,
            'sig' => $this->sig,
        ];
    }
}
