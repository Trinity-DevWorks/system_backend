<?php

namespace App\Modules\Inventory\UnitOfMeasurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\UnitOfMeasurement\DTOs\UnitOfMeasurementData;
use App\Modules\Inventory\UnitOfMeasurement\DTOs\UnitOfMeasurementResponseData;
use App\Modules\Inventory\UnitOfMeasurement\Http\Requests\StoreUnitOfMeasurementRequest;
use App\Modules\Inventory\UnitOfMeasurement\Http\Requests\UpdateUnitOfMeasurementRequest;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Inventory\UnitOfMeasurement\Services\UnitOfMeasurementService;
use Illuminate\Http\JsonResponse;

class UnitOfMeasurementController extends Controller
{
    public function __construct(
        private readonly UnitOfMeasurementService $unitOfMeasurementService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            UnitOfMeasurementResponseData::collectionToArray($this->unitOfMeasurementService->list()),
            'Units of measurement fetched successfully.'
        );
    }

    public function store(StoreUnitOfMeasurementRequest $request): JsonResponse
    {
        $uom = $this->unitOfMeasurementService->create(
            UnitOfMeasurementData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            UnitOfMeasurementResponseData::fromModel($uom)->toArray(),
            'Unit of measurement created successfully.'
        );
    }

    public function show(UnitOfMeasurement $unitOfMeasurement): JsonResponse
    {
        $unitOfMeasurement->load('unitGroup:id,code,name,dimension_type');

        return ApiResponse::success(
            UnitOfMeasurementResponseData::fromModel($unitOfMeasurement)->toArray(),
            'Unit of measurement fetched successfully.'
        );
    }

    public function update(UpdateUnitOfMeasurementRequest $request, UnitOfMeasurement $unitOfMeasurement): JsonResponse
    {
        $updated = $this->unitOfMeasurementService->update(
            $unitOfMeasurement,
            UnitOfMeasurementData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            UnitOfMeasurementResponseData::fromModel($updated)->toArray(),
            'Unit of measurement updated successfully.'
        );
    }

    public function destroy(UnitOfMeasurement $unitOfMeasurement): JsonResponse
    {
        $this->unitOfMeasurementService->delete($unitOfMeasurement);

        return ApiResponse::success(null, 'Unit of measurement deleted successfully.');
    }
}
