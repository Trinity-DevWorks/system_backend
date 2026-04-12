<?php

namespace App\Modules\Inventory\UnitOfMeasurement\DTOs;

use App\Modules\Inventory\UnitOfMeasurement\Http\Requests\StoreUnitOfMeasurementRequest;
use App\Modules\Inventory\UnitOfMeasurement\Http\Requests\UpdateUnitOfMeasurementRequest;

readonly class UnitOfMeasurementData
{
    public function __construct(
        public int $unitGroupId,
        public string $code,
        public string $name,
        public ?string $symbol,
        public int $decimalPlaces,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StoreUnitOfMeasurementRequest $request): self
    {
        $data = $request->validated();

        return new self(
            unitGroupId: (int) $data['unit_group_id'],
            code: $data['code'],
            name: $data['name'],
            symbol: $data['symbol'] ?? null,
            decimalPlaces: (int) $data['decimal_places'],
            isActive: (bool) $data['is_active'],
        );
    }

    public static function fromUpdateRequest(UpdateUnitOfMeasurementRequest $request): self
    {
        $data = $request->validated();

        return new self(
            unitGroupId: (int) $data['unit_group_id'],
            code: $data['code'],
            name: $data['name'],
            symbol: $data['symbol'] ?? null,
            decimalPlaces: (int) $data['decimal_places'],
            isActive: (bool) $data['is_active'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unit_group_id' => $this->unitGroupId,
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimalPlaces,
            'is_active' => $this->isActive,
        ];
    }
}
