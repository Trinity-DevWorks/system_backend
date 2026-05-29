<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\DTOs;

use App\Modules\Warehouse\Http\Requests\StoreWarehouseRequest;
use App\Modules\Warehouse\Http\Requests\UpdateWarehouseRequest;

readonly class WarehouseData
{
    public function __construct(
        public string $name,
        public string $shortcutName,
        public bool $isActive,
        public bool $isDefault,
        public bool $isDefaultSales,
        public bool $isDefaultProduction,
        public bool $isDefaultPurchase,
        public bool $isDefaultStorage,
    ) {}

    public static function fromStoreRequest(StoreWarehouseRequest $request): self
    {
        return self::fromValidated($request->validated());
    }

    public static function fromUpdateRequest(UpdateWarehouseRequest $request): self
    {
        return self::fromValidated($request->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromValidated(array $data): self
    {
        return new self(
            name: $data['name'],
            shortcutName: $data['shortcut_name'],
            isActive: (bool) $data['is_active'],
            isDefault: (bool) $data['is_default'],
            isDefaultSales: (bool) $data['is_default_sales'],
            isDefaultProduction: (bool) $data['is_default_production'],
            isDefaultPurchase: (bool) $data['is_default_purchase'],
            isDefaultStorage: (bool) $data['is_default_storage'],
        );
    }

    /**
     * @return array<string, bool>
     */
    public function defaultFlags(): array
    {
        return [
            'is_default' => $this->isDefault,
            'is_default_sales' => $this->isDefaultSales,
            'is_default_production' => $this->isDefaultProduction,
            'is_default_purchase' => $this->isDefaultPurchase,
            'is_default_storage' => $this->isDefaultStorage,
        ];
    }

    /**
     * @return array{
     *     name:string,
     *     shortcut_name:string,
     *     is_active:bool,
     *     is_default:bool,
     *     is_default_sales:bool,
     *     is_default_production:bool,
     *     is_default_purchase:bool,
     *     is_default_storage:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'shortcut_name' => $this->shortcutName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'is_default_sales' => $this->isDefaultSales,
            'is_default_production' => $this->isDefaultProduction,
            'is_default_purchase' => $this->isDefaultPurchase,
            'is_default_storage' => $this->isDefaultStorage,
        ];
    }
}
