<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\DTOs;

use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class WarehouseResponseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $shortcutName,
        public string $type,
        public ?int $branchId,
        public ?string $branchName,
        public ?string $address,
        public ?string $description,
        public ?string $managerId,
        public ?string $managerName,
        public bool $isActive,
        public bool $isDefault,
        public bool $isDefaultSales,
        public bool $isDefaultProduction,
        public bool $isDefaultPurchase,
        public bool $isDefaultStorage,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Warehouse $warehouse): self
    {
        $warehouse->loadMissing(['branch:id,name', 'manager:id,name']);
        $branch = $warehouse->branch;
        $manager = $warehouse->manager;
        $type = $warehouse->type instanceof WarehouseType
            ? $warehouse->type
            : WarehouseType::from((string) $warehouse->type);

        return new self(
            id: $warehouse->id,
            name: $warehouse->name,
            shortcutName: $warehouse->shortcut_name,
            type: $type->value,
            branchId: $warehouse->branch_id,
            branchName: $branch?->name,
            address: $warehouse->address,
            description: $warehouse->description,
            managerId: $warehouse->manager_id,
            managerName: $manager?->name,
            isActive: (bool) $warehouse->is_active,
            isDefault: (bool) $warehouse->is_default,
            isDefaultSales: (bool) $warehouse->is_default_sales,
            isDefaultProduction: (bool) $warehouse->is_default_production,
            isDefaultPurchase: (bool) $warehouse->is_default_purchase,
            isDefaultStorage: (bool) $warehouse->is_default_storage,
            createdAt: (string) $warehouse->created_at,
            updatedAt: (string) $warehouse->updated_at,
        );
    }

    /**
     * @param  Collection<int, Warehouse>  $warehouses
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $warehouses): array
    {
        return $warehouses
            ->map(fn (Warehouse $warehouse): array => self::fromModel($warehouse)->toArray())
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
            'shortcut_name' => $this->shortcutName,
            'type' => $this->type,
            'branch_id' => $this->branchId,
            'branch_name' => $this->branchName,
            'address' => $this->address,
            'description' => $this->description,
            'manager_id' => $this->managerId,
            'manager_name' => $this->managerName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'is_default_sales' => $this->isDefaultSales,
            'is_default_production' => $this->isDefaultProduction,
            'is_default_purchase' => $this->isDefaultPurchase,
            'is_default_storage' => $this->isDefaultStorage,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
