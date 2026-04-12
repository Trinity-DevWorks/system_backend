<?php

namespace App\Modules\SubCategory\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\SubCategory\DTOs\SubCategoryData;
use App\Modules\SubCategory\DTOs\SubCategoryResponseData;
use App\Modules\SubCategory\Http\Requests\StoreSubCategoryRequest;
use App\Modules\SubCategory\Http\Requests\UpdateSubCategoryRequest;
use App\Modules\SubCategory\Models\SubCategory;
use App\Modules\SubCategory\Services\SubCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{
    use ResolvesListSection;

    private const INDEX_SECTIONS = ['names'];

    public function __construct(
        private readonly SubCategoryService $subCategoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->resolveListSection($request, self::INDEX_SECTIONS) === 'names') {
            return ApiResponse::success(
                SubCategoryResponseData::collectionToArray($this->subCategoryService->names()),
                'Sub category names fetched successfully.'
            );
        }

        return ApiResponse::success(
            SubCategoryResponseData::collectionToArray($this->subCategoryService->list()),
            'Sub categories fetched successfully.'
        );
    }

    public function store(StoreSubCategoryRequest $request): JsonResponse
    {
        $subCategory = $this->subCategoryService->create(
            SubCategoryData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            SubCategoryResponseData::fromModel($subCategory)->toArray(),
            'Sub category created successfully.'
        );
    }

    public function show(SubCategory $subCategory): JsonResponse
    {
        $subCategory->load('category:id,name');

        return ApiResponse::success(
            SubCategoryResponseData::fromModel($subCategory)->toArray(),
            'Sub category fetched successfully.'
        );
    }

    public function update(UpdateSubCategoryRequest $request, SubCategory $subCategory): JsonResponse
    {
        $updated = $this->subCategoryService->update(
            $subCategory,
            SubCategoryData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            SubCategoryResponseData::fromModel($updated)->toArray(),
            'Sub category updated successfully.'
        );
    }

    public function destroy(SubCategory $subCategory): JsonResponse
    {
        $this->subCategoryService->delete($subCategory);

        return ApiResponse::success(null, 'Sub category deleted successfully.');
    }
}
