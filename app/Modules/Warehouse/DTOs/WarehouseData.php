<?php

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
    ) {}

    public static function fromStoreRequest(StoreWarehouseRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: $data['name'],
            shortcutName: $data['shortcut_name'],
            isActive: (bool) $data['is_active'],
            isDefault: (bool) $data['is_default'],
        );
    }

    public static function fromUpdateRequest(UpdateWarehouseRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: $data['name'],
            shortcutName: $data['shortcut_name'],
            isActive: (bool) $data['is_active'],
            isDefault: (bool) $data['is_default'],
        );
    }

    /**
     * @return array{name:string,shortcut_name:string,is_active:bool,is_default:bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'shortcut_name' => $this->shortcutName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
        ];
    }
}
