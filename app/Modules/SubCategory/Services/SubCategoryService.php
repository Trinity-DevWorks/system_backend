<?php

namespace App\Modules\SubCategory\Services;

use App\Modules\SubCategory\DTOs\SubCategoryData;
use App\Modules\SubCategory\Models\SubCategory;
use Illuminate\Database\Eloquent\Collection;

class SubCategoryService
{
    public function list(): Collection
    {
        return SubCategory::query()
            ->with('category:id,name')
            ->orderBy('name')
            ->get();
    }

    public function names(): Collection
    {
        return SubCategory::query()
            ->with('category:id,name')
            ->select(['id', 'category_id', 'name', 'color', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    public function create(SubCategoryData $data): SubCategory
    {
        $subCategory = SubCategory::query()->create($data->toArray());

        return $subCategory->load('category:id,name');
    }

    public function update(SubCategory $subCategory, SubCategoryData $data): SubCategory
    {
        $subCategory->update($data->toArray());

        return $subCategory->refresh()->load('category:id,name');
    }

    public function delete(SubCategory $subCategory): void
    {
        $subCategory->delete();
    }
}
