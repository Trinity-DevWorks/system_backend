<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use Carbon\Carbon;

/**
 * One third-party attestation read from InvoiceRegistry.attestationAt.
 * `verifierName` comes from the company's verifier list, not from the chain.
 */
readonly class InvoiceAttestationRecord
{
    public function __construct(
        public string $verifier,
        public InvoiceVerifierRole $role,
        public ?string $referenceHash,
        public ?int $attestedAt,
        public ?string $verifierName = null,
        public string $partySide = 'supplier',
    ) {}

    public function withVerifierName(?string $verifierName): self
    {
        return new self(
            $this->verifier,
            $this->role,
            $this->referenceHash,
            $this->attestedAt,
            $verifierName,
            $this->partySide,
        );
    }

    /**
     * @return array{verifier: string, verifier_name: ?string, role: string, party_side: string, reference_hash: ?string, attested_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'verifier' => $this->verifier,
            'verifier_name' => $this->verifierName,
            'role' => $this->role->value,
            'party_side' => $this->partySide === 'buyer' ? 'buyer' : 'supplier',
            'reference_hash' => $this->referenceHash,
            'attested_at' => $this->attestedAt !== null && $this->attestedAt > 0
                ? Carbon::createFromTimestampUTC($this->attestedAt)->toIso8601String()
                : null,
        ];
    }
}
