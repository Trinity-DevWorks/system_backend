<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Collection;

readonly class SupplierResponseData
{
    /**
     * @param  array<string, mixed>|null  $supplierGroup
     */
    public function __construct(
        public int $id,
        public string $supplierCode,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?int $supplierGroupId,
        public ?array $supplierGroup,
        public string $creditLimit,
        public string $openingBalance,
        public bool $isActive,
        public bool $isVatRegistered,
        public ?string $vatNumber,
        public ?string $notes,
        public string $balance,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {}

    public static function fromModel(Supplier $supplier, string $balance): self
    {
        $supplier->loadMissing('supplierGroup');

        $group = $supplier->supplierGroup;

        return new self(
            id: $supplier->id,
            supplierCode: (string) $supplier->supplier_code,
            name: $supplier->name,
            email: $supplier->email,
            phone: $supplier->phone,
            supplierGroupId: $supplier->supplier_group_id,
            supplierGroup: $group ? ['id' => $group->id, 'name' => $group->name] : null,
            creditLimit: (string) $supplier->credit_limit,
            openingBalance: (string) $supplier->opening_balance,
            isActive: (bool) $supplier->is_active,
            isVatRegistered: (bool) $supplier->is_vat_registered,
            vatNumber: $supplier->vat_number,
            notes: $supplier->notes,
            balance: $balance,
            createdAt: (string) $supplier->created_at,
            updatedAt: (string) $supplier->updated_at,
            deletedAt: $supplier->deleted_at?->toIso8601String(),
        );
    }

    /**
     * @param  Collection<int, Supplier>  $suppliers
     * @param  array<int, string>  $balancesBySupplierId
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $suppliers, array $balancesBySupplierId): array
    {
        return $suppliers
            ->map(function (Supplier $s) use ($balancesBySupplierId): array {
                $bal = $balancesBySupplierId[$s->id] ?? '0.0000';

                return self::fromModel($s, $bal)->toArray();
            })
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
            'supplier_code' => $this->supplierCode,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'supplier_group_id' => $this->supplierGroupId,
            'supplier_group' => $this->supplierGroup,
            'credit_limit' => $this->creditLimit,
            'opening_balance' => $this->openingBalance,
            'is_active' => $this->isActive,
            'is_vat_registered' => $this->isVatRegistered,
            'vat_number' => $this->vatNumber,
            'notes' => $this->notes,
            'balance' => $this->balance,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }
}
