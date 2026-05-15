<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Models\Supplier;

readonly class SupplierTableRowResponseData
{
    /**
     * @param  array{id: int, name: string}|null  $supplierGroup
     */
    public function __construct(
        public int $id,
        public string $supplierCode,
        public string $name,
        public ?string $companyName,
        public ?int $supplierGroupId,
        public ?array $supplierGroup,
        public ?string $phone,
        public ?string $email,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Supplier $supplier): self
    {
        $supplier->loadMissing(['supplierGroup:id,name']);

        $group = $supplier->supplierGroup;

        return new self(
            id: $supplier->id,
            supplierCode: (string) ($supplier->supplier_code ?? ''),
            name: $supplier->name,
            companyName: $supplier->company_name,
            supplierGroupId: $supplier->supplier_group_id,
            supplierGroup: $group ? ['id' => $group->id, 'name' => $group->name] : null,
            phone: $supplier->phone,
            email: $supplier->email,
            isActive: (bool) $supplier->is_active,
            createdAt: (string) $supplier->created_at,
            updatedAt: (string) $supplier->updated_at,
        );
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
            'company_name' => $this->companyName,
            'supplier_group_id' => $this->supplierGroupId,
            'supplier_group' => $this->supplierGroup,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
