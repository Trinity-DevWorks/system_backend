<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\PlatformBrandingResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\UpdatePlatformBrandingRequest;
use App\Http\Requests\Central\UploadPlatformLogoRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Central\PlatformBranding;
use App\Services\Central\PlatformBrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `show` and `logo` are public (login pages, page titles, favicons on every host);
 * the rest is the central Platform Settings page.
 */
class PlatformBrandingController extends Controller
{
    public function __construct(
        private readonly PlatformBrandingService $branding,
    ) {}

    public function show(): JsonResponse
    {
        return $this->respond($this->branding->get(), 'Platform branding fetched successfully.');
    }

    public function logo(Request $request): StreamedResponse|JsonResponse
    {
        $version = $request->query('v');

        return $this->branding->logoResponse(is_string($version) ? $version : null);
    }

    public function update(UpdatePlatformBrandingRequest $request): JsonResponse
    {
        return $this->respond(
            $this->branding->updateName((string) $request->validated('name')),
            'Platform branding updated successfully.'
        );
    }

    public function storeLogo(UploadPlatformLogoRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        return $this->respond($this->branding->storeLogo($file), 'Platform logo updated successfully.');
    }

    public function destroyLogo(): JsonResponse
    {
        return $this->respond($this->branding->deleteLogo(), 'Platform logo removed successfully.');
    }

    private function respond(PlatformBranding $branding, string $message): JsonResponse
    {
        return ApiResponse::success(PlatformBrandingResponseData::fromModel($branding)->toArray(), $message);
    }
}
