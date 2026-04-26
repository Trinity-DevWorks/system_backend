<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Warehouse\DTOs\WarehouseData;
use App\Modules\Warehouse\DTOs\WarehouseResponseData;
use App\Modules\Warehouse\Http\Requests\StoreWarehouseRequest;
use App\Modules\Warehouse\Http\Requests\UpdateWarehouseRequest;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Http\JsonResponse;

class WarehouseController extends Controller
{
    public function __construct(
        private readonly WarehouseService $warehouseService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            WarehouseResponseData::collectionToArray($this->warehouseService->list()),
            'Warehouses fetched successfully.'
        );
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->warehouseService->create(
            WarehouseData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            WarehouseResponseData::fromModel($warehouse)->toArray(),
            'Warehouse created successfully.'
        );
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        return ApiResponse::success(
            WarehouseResponseData::fromModel($warehouse)->toArray(),
            'Warehouse fetched successfully.'
        );
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $updated = $this->warehouseService->update(
            $warehouse,
            WarehouseData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            WarehouseResponseData::fromModel($updated)->toArray(),
            'Warehouse updated successfully.'
        );
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $this->warehouseService->delete($warehouse);

        return ApiResponse::success(null, 'Warehouse deleted successfully.');
    }
}
