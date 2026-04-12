<?php

namespace App\Modules\Category\Services;

use App\Modules\Category\DTOs\CategoryData;
use App\Modules\Category\Models\Category;
use Illuminate\Database\Eloquent\Collection;

class CategoryService
{
    public function list(): Collection
    {
        return Category::query()->orderBy('name')->get();
    }

    public function names(): Collection
    {
        return Category::query()
            ->select(['id', 'name', 'color', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    public function create(CategoryData $data): Category
    {
        return Category::query()->create($data->toArray());
    }

    public function update(Category $category, CategoryData $data): Category
    {
        $category->update($data->toArray());

        return $category->refresh();
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }
}
