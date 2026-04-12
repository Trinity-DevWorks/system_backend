<?php

namespace App\Modules\SubCategory\DTOs;

use App\Modules\Category\Models\Category;
use App\Modules\SubCategory\Models\SubCategory;
use Illuminate\Support\Collection;

readonly class SubCategoryResponseData
{
    public function __construct(
        public int $id,
        public int $categoryId,
        public ?array $category,
        public string $name,
        public string $color,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(SubCategory $subCategory): self
    {
        return new self(
            id: $subCategory->id,
            categoryId: $subCategory->category_id,
            category: self::categoryToArray($subCategory->category),
            name: $subCategory->name,
            color: $subCategory->color,
            createdAt: (string) $subCategory->created_at,
            updatedAt: (string) $subCategory->updated_at,
        );
    }

    /**
     * @param  Collection<int, SubCategory>  $subCategories
     * @return array<int, array{id:int,category_id:int,category:?array{name:string},name:string,color:string,created_at:string,updated_at:string}>
     */
    public static function collectionToArray(Collection $subCategories): array
    {
        return $subCategories
            ->map(fn (SubCategory $subCategory): array => self::fromModel($subCategory)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,category_id:int,category:?array{name:string},name:string,color:string,created_at:string,updated_at:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->categoryId,
            'category' => $this->category,
            'name' => $this->name,
            'color' => $this->color,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array{name: string}|null
     */
    private static function categoryToArray(?Category $category): ?array
    {
        if (! $category) {
            return null;
        }

        return [
            'name' => $category->name,
        ];
    }
}
