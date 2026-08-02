<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\CompanyProfile\DTOs\CompanyProfileData;
use App\Modules\CompanyProfile\DTOs\CompanyProfileResponseData;
use App\Modules\CompanyProfile\Http\Requests\UpdateCompanyProfileRequest;
use App\Modules\CompanyProfile\Services\CompanyProfileService;
use Illuminate\Http\JsonResponse;

class CompanyProfileController extends Controller
{
    public function __construct(
        private readonly CompanyProfileService $companyProfileService
    ) {}

    public function show(): JsonResponse
    {
        $profile = $this->companyProfileService->get();

        return ApiResponse::success(
            CompanyProfileResponseData::fromModel($profile)->toArray(),
            'Company profile fetched successfully.'
        );
    }

    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $profile = $this->companyProfileService->get();
        $updated = $this->companyProfileService->update(
            CompanyProfileData::fromUpdateRequest($request, $profile)
        );

        return ApiResponse::success(
            CompanyProfileResponseData::fromModel($updated)->toArray(),
            'Company profile updated successfully.'
        );
    }
}
