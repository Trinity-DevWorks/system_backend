<?php

namespace App\Modules\Inventory\UnitGroup\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\UnitGroup\DTOs\UnitGroupData;
use App\Modules\Inventory\UnitGroup\DTOs\UnitGroupResponseData;
use App\Modules\Inventory\UnitGroup\Http\Requests\StoreUnitGroupRequest;
use App\Modules\Inventory\UnitGroup\Http\Requests\UpdateUnitGroupRequest;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitGroup\Services\UnitGroupService;
use App\Modules\Inventory\UnitOfMeasurement\DTOs\UnitOfMeasurementResponseData;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitGroupController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly UnitGroupService $unitGroupService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn () => UnitGroupResponseData::collectionToArray($this->unitGroupService->list()),
            'Unit group names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->unitGroupService->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (UnitGroup $group): array => UnitGroupResponseData::fromModel($group)->toArray(),
            'Unit groups fetched successfully.'
        );
    }

    public function store(StoreUnitGroupRequest $request): JsonResponse
    {
        $group = $this->unitGroupService->create(
            UnitGroupData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            UnitGroupResponseData::fromModel($group)->toArray(),
            'Unit group created successfully.'
        );
    }

    public function show(UnitGroup $unitGroup): JsonResponse
    {
        return ApiResponse::success(
            UnitGroupResponseData::fromModel($unitGroup)->toArray(),
            'Unit group fetched successfully.'
        );
    }

    public function update(UpdateUnitGroupRequest $request, UnitGroup $unitGroup): JsonResponse
    {
        $updated = $this->unitGroupService->update(
            $unitGroup,
            UnitGroupData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            UnitGroupResponseData::fromModel($updated)->toArray(),
            'Unit group updated successfully.'
        );
    }

    public function destroy(UnitGroup $unitGroup): JsonResponse
    {
        $this->unitGroupService->delete($unitGroup);

        return ApiResponse::success(null, 'Unit group deleted successfully.');
    }

    public function units(UnitGroup $unitGroup): JsonResponse
    {
        /** @var Collection<int, UnitOfMeasurement> $units */
        $units = $this->unitGroupService->units($unitGroup);

        return ApiResponse::success(
            UnitOfMeasurementResponseData::collectionToArray($units->load('unitGroup:id,code,name,dimension_type')),
            'Units of measurement fetched successfully.'
        );
    }
}
