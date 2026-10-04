<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\PlatformSettingResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\UpdatePlatformSettingRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Central\PlatformSettingService;
use Illuminate\Http\JsonResponse;

class PlatformSettingController extends Controller
{
    public function __construct(
        private readonly PlatformSettingService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            PlatformSettingResponseData::fromModel($this->settings->get())->toArray(),
            'Platform settings fetched successfully.'
        );
    }

    public function update(UpdatePlatformSettingRequest $request): JsonResponse
    {
        return ApiResponse::success(
            PlatformSettingResponseData::fromModel($this->settings->update($request->validated()))->toArray(),
            'Platform settings updated successfully.'
        );
    }
}
