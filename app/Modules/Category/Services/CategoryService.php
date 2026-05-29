<?php

namespace App\Modules\Category\Services;

use App\Modules\Category\DTOs\CategoryData;
use App\Modules\Category\Models\Category;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    private const CACHE_LIST = 'categories.list';

    public function list(bool $forceRefresh = false): Collection
    {
        if ($forceRefresh) {
            TenantReferenceCache::forget(self::CACHE_LIST);
        }

        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Category::class,
            fn (): Collection => Category::query()
                ->with(['parent:id,code,name,parent_id'])
                ->withCount('children')
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * @return Collection<int, Category>
     */
    public function leaves(bool $activeOnly = true, bool $forceRefresh = false): Collection
    {
        $all = $this->list($forceRefresh);

        return $all
            ->filter(fn (Category $category): bool => (int) ($category->children_count ?? 0) === 0)
            ->when($activeOnly, fn (Collection $categories) => $categories->filter(
                fn (Category $category): bool => (bool) $category->is_active
            ))
            ->values();
    }

    public function create(CategoryData $data): Category
    {
        $model = Category::query()->create($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $model->load(['parent:id,code,name'])->loadCount('children');
    }

    public function update(Category $category, CategoryData $data): Category
    {
        $category->update($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $category->refresh()->load(['parent:id,code,name'])->loadCount('children');
    }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Cannot delete a category that has subcategories. Remove or reassign them first.'],
            ]);
        }

        if ($category->items()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Cannot delete a category that is assigned to items.'],
            ]);
        }

        $category->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
