<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Http\Responses\ApiResponse;
use App\Models\Central\PlatformProfile;
use App\Support\TenantReferenceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform company profile: product name, contact details, and the public logo.
 */
final class PlatformProfileService
{
    public const CACHE_KEY = 'central.platform_profile';

    /** Private disk: the logo is only served through the public branding route. */
    public const LOGO_DISK = 'local';

    private const LOGO_DIRECTORY = 'platform/branding';

    public function get(): PlatformProfile
    {
        return TenantReferenceCache::rememberModel(
            self::CACHE_KEY,
            PlatformProfile::class,
            fn (): PlatformProfile => PlatformProfile::singleton()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): PlatformProfile
    {
        $profile = PlatformProfile::singleton();
        $profile->update($data);
        $this->forgetCache();

        return $profile->refresh();
    }

    public function storeLogo(UploadedFile $file): PlatformProfile
    {
        $profile = PlatformProfile::singleton();
        $previous = $profile->logo_path;

        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs(self::LOGO_DIRECTORY, 'logo_'.Str::uuid()->toString().'.'.$extension, self::LOGO_DISK);
        if ($path === false) {
            abort(500, 'Could not store the logo.');
        }

        $profile->update([
            'logo_path' => $path,
            'logo_mime_type' => $file->getMimeType() ?: 'image/'.$extension,
            'logo_updated_at' => now(),
        ]);
        $this->forgetCache();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk(self::LOGO_DISK)->delete($previous);
        }

        return $profile->refresh();
    }

    public function deleteLogo(): PlatformProfile
    {
        $profile = PlatformProfile::singleton();
        $previous = $profile->logo_path;

        $profile->update([
            'logo_path' => null,
            'logo_mime_type' => null,
            'logo_updated_at' => now(),
        ]);
        $this->forgetCache();

        if (is_string($previous) && $previous !== '') {
            Storage::disk(self::LOGO_DISK)->delete($previous);
        }

        return $profile->refresh();
    }

    /**
     * Inline logo for `<img>` and favicons. A matching `?v=` lets browsers cache it for good.
     */
    public function logoResponse(?string $version): StreamedResponse|JsonResponse
    {
        $profile = $this->get();
        $path = $profile->logo_path;

        if (! $profile->hasLogo() || ! Storage::disk(self::LOGO_DISK)->exists((string) $path)) {
            return ApiResponse::notFound('Platform logo not found.', 'PLATFORM_LOGO_NOT_FOUND');
        }

        $versioned = $version !== null && $version === (string) self::logoVersion($profile);

        return Storage::disk(self::LOGO_DISK)->response((string) $path, basename((string) $path), [
            'Content-Type' => (string) ($profile->logo_mime_type ?: 'application/octet-stream'),
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Cache-busting token for the public logo URL; null when there is no logo.
     */
    public static function logoVersion(PlatformProfile $profile): ?int
    {
        if (! $profile->hasLogo()) {
            return null;
        }

        return $profile->logo_updated_at?->getTimestamp() ?? 0;
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
