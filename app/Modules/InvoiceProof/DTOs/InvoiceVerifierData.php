<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Enums\InvoicePartySide;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Http\Requests\StoreInvoiceVerifierRequest;
use App\Modules\InvoiceProof\Http\Requests\UpdateInvoiceVerifierRequest;

readonly class InvoiceVerifierData
{
    public function __construct(
        public string $name,
        public InvoiceVerifierRole $role,
        public ?string $walletAddress,
        public ?WalletType $walletType,
        public ?string $email,
        public ?string $phone,
        public ?string $notes,
        public InvoicePartySide $partySide = InvoicePartySide::Supplier,
    ) {}

    public static function fromStoreRequest(StoreInvoiceVerifierRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: trim((string) $data['name']),
            role: InvoiceVerifierRole::from((string) $data['role']),
            walletAddress: strtolower((string) $data['wallet_address']),
            walletType: WalletType::from((string) $data['wallet_type']),
            email: self::optional($data, 'email'),
            phone: self::optional($data, 'phone'),
            notes: self::optional($data, 'notes'),
            partySide: InvoicePartySide::from((string) $data['party_side']),
        );
    }

    public static function fromUpdateRequest(UpdateInvoiceVerifierRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: trim((string) $data['name']),
            role: InvoiceVerifierRole::from((string) $data['role']),
            walletAddress: null,
            walletType: null,
            email: self::optional($data, 'email'),
            phone: self::optional($data, 'phone'),
            notes: self::optional($data, 'notes'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optional(array $data, string $key): ?string
    {
        $value = isset($data[$key]) ? trim((string) $data[$key]) : '';

        return $value === '' ? null : $value;
    }
}
