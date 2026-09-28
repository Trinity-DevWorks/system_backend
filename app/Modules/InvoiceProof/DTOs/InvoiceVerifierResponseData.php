<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Models\InvoiceVerifier;
use Illuminate\Support\Collection;

readonly class InvoiceVerifierResponseData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $role,
        public string $walletAddress,
        public ?string $notes,
        public string $chainStatus,
        public ?string $chainCompanyWallet,
        public ?string $chainTxHash,
        public ?string $chainError,
        public ?string $chainSyncedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(InvoiceVerifier $verifier): self
    {
        return new self(
            id: (string) $verifier->id,
            name: (string) $verifier->name,
            role: $verifier->role->value,
            walletAddress: (string) $verifier->wallet_address,
            notes: $verifier->notes,
            chainStatus: $verifier->chain_status->value,
            chainCompanyWallet: $verifier->chain_company_wallet,
            chainTxHash: $verifier->chain_tx_hash,
            chainError: $verifier->chain_error,
            chainSyncedAt: $verifier->chain_synced_at?->toIso8601String(),
            createdAt: (string) $verifier->created_at,
            updatedAt: (string) $verifier->updated_at,
        );
    }

    /**
     * @param  Collection<int, InvoiceVerifier>  $verifiers
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $verifiers): array
    {
        return $verifiers
            ->map(fn (InvoiceVerifier $verifier): array => self::fromModel($verifier)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'wallet_address' => $this->walletAddress,
            'notes' => $this->notes,
            'chain_status' => $this->chainStatus,
            'chain_company_wallet' => $this->chainCompanyWallet,
            'chain_tx_hash' => $this->chainTxHash,
            'chain_error' => $this->chainError,
            'chain_synced_at' => $this->chainSyncedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
