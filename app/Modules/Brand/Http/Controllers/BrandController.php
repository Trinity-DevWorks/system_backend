<?php

namespace App\Modules\Brand\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Brand\DTOs\BrandData;
use App\Modules\Brand\DTOs\BrandResponseData;
use App\Modules\Brand\Http\Requests\StoreBrandRequest;
use App\Modules\Brand\Http\Requests\UpdateBrandRequest;
use App\Modules\Brand\Models\Brand;
use App\Modules\Brand\Services\BrandService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly BrandService $brandService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn () => BrandResponseData::collectionToArray($this->brandService->list()),
            'Brand names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->brandService->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (Brand $brand): array => BrandResponseData::fromModel($brand)->toArray(),
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
