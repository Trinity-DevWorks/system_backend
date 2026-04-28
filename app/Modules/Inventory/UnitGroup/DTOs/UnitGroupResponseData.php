<?php

namespace App\Modules\Inventory\UnitGroup\DTOs;

use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use Illuminate\Support\Collection;

readonly class UnitGroupResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $dimensionType,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(UnitGroup $group): self
    {
        return new self(
            id: $group->id,
            code: $group->code,
            name: $group->name,
            dimensionType: self::dimensionTypeValue($group),
            isActive: (bool) $group->is_active,
            createdAt: (string) $group->created_at,
            updatedAt: (string) $group->updated_at,
        );
    }

    /**
     * @param  Collection<int, UnitGroup>  $groups
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $groups): array
    {
        return $groups
            ->map(fn (UnitGroup $g): array => self::fromModel($g)->toArray())
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
            'code' => $this->code,
            'name' => $this->name,
            'dimension_type' => $this->dimensionType,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
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
