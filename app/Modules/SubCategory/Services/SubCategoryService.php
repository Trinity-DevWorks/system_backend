<?php

namespace App\Modules\SubCategory\Services;

use App\Modules\SubCategory\DTOs\SubCategoryData;
use App\Modules\SubCategory\Models\SubCategory;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;

class SubCategoryService
{
    private const CACHE_LIST = 'sub_categories.list';

    private const CACHE_NAMES = 'sub_categories.names';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            SubCategory::class,
            fn (): Collection => SubCategory::query()->orderBy('name')->get()
        )->load('category:id,name');
    }

    public function names(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_NAMES,
            SubCategory::class,
            fn (): Collection => SubCategory::query()
                ->select(['id', 'category_id', 'name', 'color', 'created_at', 'updated_at'])
                ->orderBy('name')
                ->get()
        )->load('category:id,name');
    }

    public function create(SubCategoryData $data): SubCategory
    {
        $subCategory = SubCategory::query()->create($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST, self::CACHE_NAMES);

        return $subCategory->load('category:id,name');
    }

    public function update(SubCategory $subCategory, SubCategoryData $data): SubCategory
    {
        $subCategory->update($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST, self::CACHE_NAMES);

        return $subCategory->refresh()->load('category:id,name');
    }

    public function delete(SubCategory $subCategory): void
    {
        $subCategory->delete();
        TenantReferenceCache::forget(self::CACHE_LIST, self::CACHE_NAMES);
    }
}
