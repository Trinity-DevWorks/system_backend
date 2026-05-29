<?php

namespace App\Modules\Brand\DTOs;

use App\Modules\Brand\Models\Brand;
use Illuminate\Support\Collection;

readonly class BrandResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?int $parentBrandId,
        public ?array $parentBrand,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Brand $brand): self
    {
        return new self(
            id: $brand->id,
            code: $brand->code,
            name: $brand->name,
            parentBrandId: $brand->parent_brand_id,
            parentBrand: self::parentBrandToArray($brand->parentBrand),
            isActive: (bool) $brand->is_active,
            createdAt: (string) $brand->created_at,
            updatedAt: (string) $brand->updated_at,
        );
    }

    /**
     * @param  Collection<int, Brand>  $brands
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $brands): array
    {
        return $brands
            ->map(fn (Brand $brand): array => self::fromModel($brand)->toArray())
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
            'parent_brand_id' => $this->parentBrandId,
            'parent_brand' => $this->parentBrand,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private static function parentBrandToArray(?Brand $parent): ?array
    {
        if (! $parent) {
            return null;
        }

        return [
            'id' => $parent->id,
            'code' => $parent->code,
            'name' => $parent->name,
        ];
    }
}
