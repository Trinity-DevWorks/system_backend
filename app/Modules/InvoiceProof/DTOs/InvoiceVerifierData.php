<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Http\Requests\StoreInvoiceVerifierRequest;
use App\Modules\InvoiceProof\Http\Requests\UpdateInvoiceVerifierRequest;

readonly class InvoiceVerifierData
{
    public function __construct(
        public string $name,
        public InvoiceVerifierRole $role,
        public ?string $walletAddress,
        public ?string $notes,
    ) {}

    public static function fromStoreRequest(StoreInvoiceVerifierRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: trim((string) $data['name']),
            role: InvoiceVerifierRole::from((string) $data['role']),
            walletAddress: strtolower((string) $data['wallet_address']),
            notes: self::notes($data),
        );
    }

    public static function fromUpdateRequest(UpdateInvoiceVerifierRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: trim((string) $data['name']),
            role: InvoiceVerifierRole::from((string) $data['role']),
            walletAddress: null,
            notes: self::notes($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function notes(array $data): ?string
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

        return $notes === '' ? null : $notes;
    }
}
