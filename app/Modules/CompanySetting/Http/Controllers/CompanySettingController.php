<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\CompanySetting\DTOs\CompanySettingData;
use App\Modules\CompanySetting\DTOs\CompanySettingResponseData;
use App\Modules\CompanySetting\Http\Requests\UpdateCompanySettingRequest;
use App\Modules\CompanySetting\Services\CompanySettingService;
use Illuminate\Http\JsonResponse;

class CompanySettingController extends Controller
{
    public function __construct(
        private readonly CompanySettingService $companySettingService
    ) {}

    public function show(): JsonResponse
    {
        $settings = $this->companySettingService->get();

        return ApiResponse::success(
            CompanySettingResponseData::fromModel($settings)->toArray(),
            'Company settings fetched successfully.'
        );
    }

    public function update(UpdateCompanySettingRequest $request): JsonResponse
    {
        $settings = $this->companySettingService->get();
        $updated = $this->companySettingService->update(
            CompanySettingData::fromUpdateRequest($request, $settings)
        );

        return ApiResponse::success(
            CompanySettingResponseData::fromModel($updated)->toArray(),
            'Company settings updated successfully.'
        );
    }
}
