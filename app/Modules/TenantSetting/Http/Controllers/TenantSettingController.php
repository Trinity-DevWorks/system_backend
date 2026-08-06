<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\TenantSetting\DTOs\TenantSettingData;
use App\Modules\TenantSetting\DTOs\TenantSettingResponseData;
use App\Modules\TenantSetting\Http\Requests\UpdateTenantSettingRequest;
use App\Modules\TenantSetting\Services\TenantSettingService;
use Illuminate\Http\JsonResponse;

class TenantSettingController extends Controller
{
    public function __construct(
        private readonly TenantSettingService $tenantSettingService
    ) {}

    public function show(): JsonResponse
    {
        $settings = $this->tenantSettingService->get();

        return ApiResponse::success(
            TenantSettingResponseData::fromModel($settings)->toArray(),
            'Tenant settings fetched successfully.'
        );
    }

    public function update(UpdateTenantSettingRequest $request): JsonResponse
    {
        $settings = $this->tenantSettingService->get();
        $updated = $this->tenantSettingService->update(
            TenantSettingData::fromUpdateRequest($request, $settings)
        );

        return ApiResponse::success(
            TenantSettingResponseData::fromModel($updated)->toArray(),
            'Tenant settings updated successfully.'
        );
    }
}
