<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Supplier\DTOs\SupplierGroupData;
use App\Modules\Supplier\DTOs\SupplierGroupResponseData;
use App\Modules\Supplier\Http\Requests\StoreSupplierGroupRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierGroupRequest;
use App\Modules\Supplier\Models\SupplierGroup;
use App\Modules\Supplier\Services\SupplierGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierGroupController extends Controller
{
    use ResolvesListSection;

    private const INDEX_SECTIONS = ['names'];

    public function __construct(
        private readonly SupplierGroupService $supplierGroupService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->resolveListSection($request, self::INDEX_SECTIONS) === 'names') {
            return ApiResponse::success(
                SupplierGroupResponseData::collectionToArray($this->supplierGroupService->names()),
                'Supplier group names fetched successfully.'
            );
        }

        return ApiResponse::success(
            SupplierGroupResponseData::collectionToArray($this->supplierGroupService->list()),
            'Supplier groups fetched successfully.'
        );
    }

    public function store(StoreSupplierGroupRequest $request): JsonResponse
    {
        $group = $this->supplierGroupService->create(SupplierGroupData::fromStoreRequest($request));

        return ApiResponse::created(
            SupplierGroupResponseData::fromModel($group)->toArray(),
            'Supplier group created successfully.'
        );
    }

    public function show(SupplierGroup $supplier_group): JsonResponse
    {
        return ApiResponse::success(
            SupplierGroupResponseData::fromModel($supplier_group)->toArray(),
            'Supplier group fetched successfully.'
        );
    }

    public function update(UpdateSupplierGroupRequest $request, SupplierGroup $supplier_group): JsonResponse
    {
        $updated = $this->supplierGroupService->update($supplier_group, SupplierGroupData::fromUpdateRequest($request));

        return ApiResponse::success(
            SupplierGroupResponseData::fromModel($updated)->toArray(),
            'Supplier group updated successfully.'
        );
    }

    public function destroy(SupplierGroup $supplier_group): JsonResponse
    {
        $this->supplierGroupService->delete($supplier_group);

        return ApiResponse::success(null, 'Supplier group deleted successfully.');
    }
}
