<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\PlatformProfileResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\UpdatePlatformProfileRequest;
use App\Http\Requests\Central\UploadPlatformLogoRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Central\PlatformProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class PlatformProfileController extends Controller
{
    public function __construct(
        private readonly PlatformProfileService $profiles,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            PlatformProfileResponseData::fromModel($this->profiles->get())->toArray(),
            'Platform profile fetched successfully.'
        );
    }

    public function update(UpdatePlatformProfileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            PlatformProfileResponseData::fromModel($this->profiles->update($request->validated()))->toArray(),
            'Platform profile updated successfully.'
        );
    }

    public function storeLogo(UploadPlatformLogoRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        return ApiResponse::success(
            PlatformProfileResponseData::fromModel($this->profiles->storeLogo($file))->toArray(),
            'Platform logo updated successfully.'
        );
    }

    public function destroyLogo(): JsonResponse
    {
        return ApiResponse::success(
            PlatformProfileResponseData::fromModel($this->profiles->deleteLogo())->toArray(),
            'Platform logo removed successfully.'
        );
    }
}
