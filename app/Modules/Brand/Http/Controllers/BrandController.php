<?php

namespace App\Modules\Brand\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Brand\DTOs\BrandData;
use App\Modules\Brand\DTOs\BrandResponseData;
use App\Modules\Brand\Http\Requests\StoreBrandRequest;
use App\Modules\Brand\Http\Requests\UpdateBrandRequest;
use App\Modules\Brand\Models\Brand;
use App\Modules\Brand\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function __construct(
        private readonly BrandService $brandService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $forceRefresh = $request->boolean('refresh');

        return ApiResponse::success(
            BrandResponseData::collectionToArray($this->brandService->list($forceRefresh)),
            'Brands fetched successfully.'
        );
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->brandService->create(
            BrandData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            BrandResponseData::fromModel($brand)->toArray(),
            'Brand created successfully.'
        );
    }

    public function show(Brand $brand): JsonResponse
    {
        $brand->load('parentBrand');

        return ApiResponse::success(
            BrandResponseData::fromModel($brand)->toArray(),
            'Brand fetched successfully.'
        );
    }

    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $updated = $this->brandService->update(
            $brand,
            BrandData::fromUpdateRequest($request, $brand)
        );

        return ApiResponse::success(
            BrandResponseData::fromModel($updated)->toArray(),
            'Brand updated successfully.'
        );
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->brandService->delete($brand);

        return ApiResponse::success(null, 'Brand deleted successfully.');
    }
}
