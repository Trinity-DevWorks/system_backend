<?php

declare(strict_types=1);

namespace App\Modules\Category\Support;

use App\Modules\Category\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CategoryTree
{
    public static function isLeaf(int $categoryId): bool
    {
        return ! Category::query()->where('parent_id', $categoryId)->exists();
    }

    public static function assertLeaf(int $categoryId): void
    {
        if (! self::isLeaf($categoryId)) {
            throw ValidationException::withMessages([
                'category_id' => ['The selected category must be a leaf category (one with no subcategories).'],
            ]);
        }
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    public static function pathLabel(Category $category, Collection $categories): string
    {
        $byId = $categories->keyBy('id');
        $parts = [];
        $current = $category;

        while ($current !== null) {
            array_unshift($parts, $current->name);
            $parentId = $current->parent_id;
            $current = $parentId !== null ? $byId->get($parentId) : null;
        }

        return implode(' / ', $parts);
    }

    public static function isDescendant(int $candidateParentId, int $categoryId): bool
    {
        $currentId = $candidateParentId;

        while ($currentId !== null) {
            if ($currentId === $categoryId) {
                return true;
            }

            $currentId = Category::query()
                ->whereKey($currentId)
                ->value('parent_id');
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public static function descendantIds(int $categoryId): array
    {
        $ids = [];
        $queue = [$categoryId];

        while ($queue !== []) {
            $parentId = array_shift($queue);
            $childIds = Category::query()
                ->where('parent_id', $parentId)
                ->pluck('id')
                ->all();

            foreach ($childIds as $childId) {
                $ids[] = (int) $childId;
                $queue[] = (int) $childId;
            }
        }

        return $ids;
    }
}
