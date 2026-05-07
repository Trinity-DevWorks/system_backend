<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Models\SupplierAddress;
use Illuminate\Support\Collection;

readonly class SupplierAddressResponseData
{
    public function __construct(
        public int $id,
        public int $supplierId,
        public string $addressType,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $state,
        public string $country,
        public bool $isDefault,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(SupplierAddress $address): self
    {
        return new self(
            id: $address->id,
            supplierId: $address->supplier_id,
            addressType: (string) $address->address_type,
            addressLine1: $address->address_line_1,
            addressLine2: $address->address_line_2,
            city: $address->city,
            state: $address->state,
            country: $address->country,
            isDefault: (bool) $address->is_default,
            createdAt: (string) $address->created_at,
            updatedAt: (string) $address->updated_at,
        );
    }

    /**
     * @param  Collection<int, SupplierAddress>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (SupplierAddress $a): array => self::fromModel($a)->toArray())
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
            'supplier_id' => $this->supplierId,
            'address_type' => $this->addressType,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'is_default' => $this->isDefault,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
