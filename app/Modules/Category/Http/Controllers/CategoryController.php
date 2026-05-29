<?php

namespace App\Modules\Category\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Category\DTOs\CategoryData;
use App\Modules\Category\DTOs\CategoryResponseData;
use App\Modules\Category\Http\Requests\StoreCategoryRequest;
use App\Modules\Category\Http\Requests\UpdateCategoryRequest;
use App\Modules\Category\Models\Category;
use App\Modules\Category\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $forceRefresh = $request->boolean('refresh');
        $leavesOnly = $request->boolean('leaves_only') || $request->boolean('assignable');

        if ($leavesOnly) {
            $activeOnly = ! $request->boolean('include_inactive');
            $categories = $this->categoryService->leaves($activeOnly, $forceRefresh);

            return ApiResponse::success(
                CategoryResponseData::collectionToArray($categories),
                'Categories fetched successfully.'
            );
        }

        return ApiResponse::success(
            CategoryResponseData::collectionToArray($this->categoryService->list($forceRefresh)),
            'Categories fetched successfully.'
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create(
            CategoryData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            CategoryResponseData::fromModel($category)->toArray(),
            'Category created successfully.'
        );
    }

    public function show(Category $category): JsonResponse
    {
        $category->load(['parent:id,code,name'])->loadCount('children');

        return ApiResponse::success(
            CategoryResponseData::fromModel($category)->toArray(),
            'Category fetched successfully.'
        );
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updated = $this->categoryService->update(
            $category,
            CategoryData::fromUpdateRequest($request, $category)
        );

        return ApiResponse::success(
            CategoryResponseData::fromModel($updated)->toArray(),
            'Category updated successfully.'
        );
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->categoryService->delete($category);

        return ApiResponse::success(null, 'Category deleted successfully.');
    }
}
