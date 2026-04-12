<?php

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
            createdAt: (string) $warehouse->created_at,
            updatedAt: (string) $warehouse->updated_at,
        );
    }

    /**
     * @param  Collection<int, Warehouse>  $warehouses
     * @return array<int, array{id:int,name:string,shortcut_name:string,is_active:bool,is_default:bool,created_at:string,updated_at:string}>
     */
    public static function collectionToArray(Collection $warehouses): array
    {
        return $warehouses
            ->map(fn (Warehouse $warehouse): array => self::fromModel($warehouse)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,name:string,shortcut_name:string,is_active:bool,is_default:bool,created_at:string,updated_at:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shortcut_name' => $this->shortcutName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
