<?php

namespace App\Modules\Inventory\UnitOfMeasurement\DTOs;

use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Support\Collection;

readonly class UnitOfMeasurementResponseData
{
    /**
     * @param  array{id:int,code:string,name:string,dimension_type:string}|null  $unitGroup
     */
    public function __construct(
        public int $id,
        public int $unitGroupId,
        public ?array $unitGroup,
        public string $code,
        public string $name,
        public ?string $symbol,
        public int $decimalPlaces,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(UnitOfMeasurement $uom): self
    {
        $group = $uom->relationLoaded('unitGroup') ? $uom->unitGroup : null;

        return new self(
            id: $uom->id,
            unitGroupId: $uom->unit_group_id,
            unitGroup: self::unitGroupSummary($group),
            code: $uom->code,
            name: $uom->name,
            symbol: $uom->symbol,
            decimalPlaces: (int) $uom->decimal_places,
            isActive: (bool) $uom->is_active,
            createdAt: (string) $uom->created_at,
            updatedAt: (string) $uom->updated_at,
        );
    }

    /**
     * @param  Collection<int, UnitOfMeasurement>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (UnitOfMeasurement $u): array => self::fromModel($u)->toArray())
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
            'unit_group_id' => $this->unitGroupId,
            'unit_group' => $this->unitGroup,
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimalPlaces,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array{id:int,code:string,name:string,dimension_type:string}|null
     */
    private static function unitGroupSummary(?UnitGroup $group): ?array
    {
        if (! $group) {
            return null;
        }

        return [
            'id' => $group->id,
            'code' => $group->code,
            'name' => $group->name,
            'dimension_type' => self::dimensionTypeValue($group),
        ];
    }

    private static function dimensionTypeValue(UnitGroup $group): string
    {
        $raw = $group->getRawOriginal('dimension_type');
        if (! is_string($raw)) {
            return '';
        }

        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        return DimensionType::tryFrom($value)?->value ?? $value;
    }
}
