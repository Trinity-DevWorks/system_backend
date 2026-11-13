<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\FixedAsset\Http\Requests\FixedAssetCategoryRequest;
use App\Modules\Accounting\FixedAsset\Models\FixedAssetCategory;
use App\Modules\Accounting\FixedAsset\Services\FixedAssetCategoryService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetCategoryController extends Controller
{
    public function __construct(
        private readonly FixedAssetCategoryService $categories,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->categories->paginate([
                'search' => ListPagination::search($request),
                'active' => $request->query('active'),
            ], ListPagination::perPage($request)),
            fn (FixedAssetCategory $category): array => $this->categories->present($category),
            'Asset categories fetched successfully.',
        );
    }

    public function defaults(): JsonResponse
    {
        return ApiResponse::success($this->categories->defaults(), 'Asset category defaults fetched successfully.');
    }

    public function show(FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        return ApiResponse::success($this->categories->present($this->categories->find((int) $fixedAssetCategory->id)), 'Asset category fetched successfully.');
    }

    public function store(FixedAssetCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->validated());

        return ApiResponse::success($this->categories->present($category), 'Asset category created successfully.', 201);
    }

    public function update(FixedAssetCategoryRequest $request, FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        $category = $this->categories->update($fixedAssetCategory, $request->validated());

        return ApiResponse::success($this->categories->present($category), 'Asset category updated successfully.');
    }

    public function destroy(FixedAssetCategory $fixedAssetCategory): JsonResponse
    {
        $this->categories->delete($fixedAssetCategory);

        return ApiResponse::success(null, 'Asset category deleted successfully.');
    }
}
