<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Http\Responses\ApiResponse;
use App\Models\Central\PlatformBranding;
use App\Support\TenantReferenceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform branding: product name and the public logo.
 */
final class PlatformBrandingService
{
    public const CACHE_KEY = 'central.platform_branding';

    /** Private disk: the logo is only served through the public branding route. */
    public const LOGO_DISK = 'local';

    private const LOGO_DIRECTORY = 'platform/branding';

    public function get(): PlatformBranding
    {
        return TenantReferenceCache::rememberModel(
            self::CACHE_KEY,
            PlatformBranding::class,
            fn (): PlatformBranding => PlatformBranding::singleton()
        );
    }

    public function updateName(string $name): PlatformBranding
    {
        $branding = PlatformBranding::singleton();
        $branding->update(['name' => $name]);
        $this->forgetCache();

        return $branding->refresh();
    }

    public function storeLogo(UploadedFile $file): PlatformBranding
    {
        $branding = PlatformBranding::singleton();
        $previous = $branding->logo_path;

        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs(self::LOGO_DIRECTORY, 'logo_'.Str::uuid()->toString().'.'.$extension, self::LOGO_DISK);
        if ($path === false) {
            abort(500, 'Could not store the logo.');
        }

        $branding->update([
            'logo_path' => $path,
            'logo_mime_type' => $file->getMimeType() ?: 'image/'.$extension,
            'logo_updated_at' => now(),
        ]);
        $this->forgetCache();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk(self::LOGO_DISK)->delete($previous);
        }

        return $branding->refresh();
    }

    public function deleteLogo(): PlatformBranding
    {
        $branding = PlatformBranding::singleton();
        $previous = $branding->logo_path;

        $branding->update([
            'logo_path' => null,
            'logo_mime_type' => null,
            'logo_updated_at' => now(),
        ]);
        $this->forgetCache();

        if (is_string($previous) && $previous !== '') {
            Storage::disk(self::LOGO_DISK)->delete($previous);
        }

        return $branding->refresh();
    }

    /**
     * Inline logo for `<img>` and favicons. A matching `?v=` lets browsers cache it for good.
     */
    public function logoResponse(?string $version): StreamedResponse|JsonResponse
    {
        $branding = $this->get();
        $path = (string) $branding->logo_path;

        if (! $branding->hasLogo() || ! Storage::disk(self::LOGO_DISK)->exists($path)) {
            return ApiResponse::notFound('Platform logo not found.', 'PLATFORM_LOGO_NOT_FOUND');
        }

        $versioned = $version !== null && $version === (string) self::logoVersion($branding);

        return Storage::disk(self::LOGO_DISK)->response($path, basename($path), [
            'Content-Type' => (string) ($branding->logo_mime_type ?: 'application/octet-stream'),
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Cache-busting token for the public logo URL; null when there is no logo.
     */
    public static function logoVersion(PlatformBranding $branding): ?int
    {
        if (! $branding->hasLogo()) {
            return null;
        }

        return $branding->logo_updated_at?->getTimestamp() ?? 0;
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
