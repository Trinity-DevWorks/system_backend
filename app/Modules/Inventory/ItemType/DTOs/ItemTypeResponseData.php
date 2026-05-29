<?php

namespace App\Modules\Inventory\ItemType\DTOs;

use App\Modules\Inventory\ItemType\Models\ItemType;
use Illuminate\Support\Collection;

readonly class ItemTypeResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public bool $isSystem,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(ItemType $itemType): self
    {
        return new self(
            id: $itemType->id,
            code: $itemType->code,
            name: $itemType->name,
            isSystem: (bool) $itemType->is_system,
            isActive: (bool) $itemType->is_active,
            createdAt: (string) $itemType->created_at,
            updatedAt: (string) $itemType->updated_at,
        );
    }

    /**
     * @param  Collection<int, ItemType>  $itemTypes
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $itemTypes): array
    {
        return $itemTypes
            ->map(fn (ItemType $itemType): array => self::fromModel($itemType)->toArray())
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
            'is_system' => $this->isSystem,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
