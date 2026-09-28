<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Enums\WalletType;

/**
 * What the active network holds at an address: a personal wallet (no code), a Safe
 * (owners readable), or another contract that can neither sign nor act as a Safe.
 */
readonly class WalletInspectionData
{
    public const KIND_WALLET = 'wallet';

    public const KIND_SAFE = 'safe';

    public const KIND_CONTRACT = 'contract';

    /**
     * @param  list<string>  $owners  Normalized 0x addresses; empty unless kind is safe.
     */
    public function __construct(
        public string $address,
        public string $kind,
        public array $owners,
        public ?int $threshold,
    ) {}

    public static function wallet(string $address): self
    {
        return new self($address, self::KIND_WALLET, [], null);
    }

    public static function contract(string $address): self
    {
        return new self($address, self::KIND_CONTRACT, [], null);
    }

    /**
     * @param  list<string>  $owners
     */
    public static function safe(string $address, array $owners, ?int $threshold): self
    {
        return new self($address, self::KIND_SAFE, $owners, $threshold);
    }

    public function matches(WalletType $type): bool
    {
        return match ($type) {
            WalletType::Wallet => $this->kind === self::KIND_WALLET,
            WalletType::Safe => $this->kind === self::KIND_SAFE,
        };
    }

    /**
     * @return array{address: string, kind: string, owners: list<string>, threshold: int|null}
     */
    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'kind' => $this->kind,
            'owners' => $this->owners,
            'threshold' => $this->threshold,
        ];
    }
}
