<?php

namespace App\Modules\Category\Services;

use App\Modules\Category\DTOs\CategoryData;
use App\Modules\Category\Models\Category;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;

class CategoryService
{
    private const CACHE_LIST = 'categories.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Category::class,
            fn (): Collection => Category::query()->orderBy('name')->get()
        );
    }

    public function create(CategoryData $data): Category
    {
        $model = Category::query()->create($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $model;
    }

    public function update(Category $category, CategoryData $data): Category
    {
        $category->update($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $category->refresh();
    }

    public function delete(Category $category): void
    {
        $category->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
