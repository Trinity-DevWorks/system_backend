<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\PlatformBrandingResponseData;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Central\PlatformProfileService;
use App\Services\Central\PlatformSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public branding for login pages, shells, page titles, and favicons on every host.
 */
class PlatformBrandingController extends Controller
{
    public function __construct(
        private readonly PlatformProfileService $profiles,
        private readonly PlatformSettingService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            PlatformBrandingResponseData::fromModels($this->profiles->get(), $this->settings->get())->toArray(),
            'Platform branding fetched successfully.'
        );
    }

    public function logo(Request $request): StreamedResponse|JsonResponse
    {
        $version = $request->query('v');

        return $this->profiles->logoResponse(is_string($version) ? $version : null);
    }
}
