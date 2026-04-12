<?php

namespace App\Modules\VatGroup\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\VatGroup\DTOs\VatGroupData;
use App\Modules\VatGroup\DTOs\VatGroupResponseData;
use App\Modules\VatGroup\Http\Requests\StoreVatGroupRequest;
use App\Modules\VatGroup\Http\Requests\UpdateVatGroupRequest;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\VatGroup\Services\VatGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VatGroupController extends Controller
{
    use ResolvesListSection;

    private const INDEX_SECTIONS = ['names'];

    public function __construct(
        private readonly VatGroupService $vatGroupService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->resolveListSection($request, self::INDEX_SECTIONS) === 'names') {
            return ApiResponse::success(
                VatGroupResponseData::collectionToArray($this->vatGroupService->names()),
                'Vat group names fetched successfully.'
            );
        }

        return ApiResponse::success(
            VatGroupResponseData::collectionToArray($this->vatGroupService->list()),
            'Vat groups fetched successfully.'
        );
    }

    public function store(StoreVatGroupRequest $request): JsonResponse
    {
        $vatGroup = $this->vatGroupService->create(
            VatGroupData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            VatGroupResponseData::fromModel($vatGroup)->toArray(),
            'Vat group created successfully.'
        );
    }

    public function show(VatGroup $vatGroup): JsonResponse
    {
        return ApiResponse::success(
            VatGroupResponseData::fromModel($vatGroup)->toArray(),
            'Vat group fetched successfully.'
        );
    }

    public function update(UpdateVatGroupRequest $request, VatGroup $vatGroup): JsonResponse
    {
        $updated = $this->vatGroupService->update(
            $vatGroup,
            VatGroupData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            VatGroupResponseData::fromModel($updated)->toArray(),
            'Vat group updated successfully.'
        );
    }

    public function destroy(VatGroup $vatGroup): JsonResponse
    {
        $this->vatGroupService->delete($vatGroup);

        return ApiResponse::success(null, 'Vat group deleted successfully.');
    }
}
