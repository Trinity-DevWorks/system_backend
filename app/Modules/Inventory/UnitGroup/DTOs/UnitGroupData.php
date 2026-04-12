<?php

namespace App\Modules\Inventory\UnitGroup\DTOs;

use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Inventory\UnitGroup\Http\Requests\StoreUnitGroupRequest;
use App\Modules\Inventory\UnitGroup\Http\Requests\UpdateUnitGroupRequest;

readonly class UnitGroupData
{
    public function __construct(
        public string $code,
        public string $name,
        public DimensionType $dimensionType,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StoreUnitGroupRequest $request): self
    {
        $data = $request->validated();

        return new self(
            code: $data['code'],
            name: $data['name'],
            dimensionType: DimensionType::from($data['dimension_type']),
            isActive: (bool) $data['is_active'],
        );
    }

    public static function fromUpdateRequest(UpdateUnitGroupRequest $request): self
    {
        $data = $request->validated();

        return new self(
            code: $data['code'],
            name: $data['name'],
            dimensionType: DimensionType::from($data['dimension_type']),
            isActive: (bool) $data['is_active'],
        );
    }

    /**
     * @return array{code:string,name:string,dimension_type:string,is_active:bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'dimension_type' => $this->dimensionType->value,
            'is_active' => $this->isActive,
        ];
    }
}
