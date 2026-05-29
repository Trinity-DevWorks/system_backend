<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\DTOs;

use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class WarehouseResponseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $shortcutName,
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
        return new self(
            id: $warehouse->id,
            name: $warehouse->name,
            shortcutName: $warehouse->shortcut_name,
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
     * @return list<array{
     *     id:int,
     *     name:string,
     *     shortcut_name:string,
     *     is_active:bool,
     *     is_default:bool,
     *     is_default_sales:bool,
     *     is_default_production:bool,
     *     is_default_purchase:bool,
     *     is_default_storage:bool,
     *     created_at:string,
     *     updated_at:string
     * }>
     */
    public static function collectionToArray(Collection $warehouses): array
    {
        return $warehouses
            ->map(fn (Warehouse $warehouse): array => self::fromModel($warehouse)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     id:int,
     *     name:string,
     *     shortcut_name:string,
     *     is_active:bool,
     *     is_default:bool,
     *     is_default_sales:bool,
     *     is_default_production:bool,
     *     is_default_purchase:bool,
     *     is_default_storage:bool,
     *     created_at:string,
     *     updated_at:string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shortcut_name' => $this->shortcutName,
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
