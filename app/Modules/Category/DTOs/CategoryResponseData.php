<?php

namespace App\Modules\Category\DTOs;

use App\Modules\Category\Models\Category;
use App\Modules\Category\Support\CategoryTree;
use Illuminate\Support\Collection;

readonly class CategoryResponseData
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public ?array $parent,
        public string $code,
        public string $name,
        public string $color,
        public ?string $description,
        public bool $isActive,
        public bool $hasChildren,
        public bool $isLeaf,
        public string $pathLabel,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Category $category, ?Collection $allCategories = null): self
    {
        $hasChildren = $category->relationLoaded('children')
            ? $category->children->isNotEmpty()
            : (int) ($category->children_count ?? 0) > 0;

        $all = $allCategories ?? Category::query()->get(['id', 'name', 'parent_id']);
        $pathLabel = CategoryTree::pathLabel($category, $all);

        return new self(
            id: $category->id,
            parentId: $category->parent_id,
            parent: self::parentBrief($category),
            code: $category->code,
            name: $category->name,
            color: $category->color,
            description: $category->description,
            isActive: (bool) $category->is_active,
            hasChildren: $hasChildren,
            isLeaf: ! $hasChildren,
            pathLabel: $pathLabel,
            createdAt: (string) $category->created_at,
            updatedAt: (string) $category->updated_at,
        );
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $categories): array
    {
        return $categories
            ->map(fn (Category $category): array => self::fromModel($category, $categories)->toArray())
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
            'parent_id' => $this->parentId,
            'parent' => $this->parent,
            'code' => $this->code,
            'name' => $this->name,
            'color' => $this->color,
            'description' => $this->description,
            'is_active' => $this->isActive,
            'has_children' => $this->hasChildren,
            'is_leaf' => $this->isLeaf,
            'path_label' => $this->pathLabel,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function parentBrief(Category $category): ?array
    {
        $parent = $category->parent;
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
