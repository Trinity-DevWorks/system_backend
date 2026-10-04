<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Models\Central\PlatformSetting;
use App\Support\TenantReferenceCache;

/**
 * Platform regional settings: central console formats and defaults for new tenants.
 */
final class PlatformSettingService
{
    public const CACHE_KEY = 'central.platform_settings';

    public function get(): PlatformSetting
    {
        return TenantReferenceCache::rememberModel(
            self::CACHE_KEY,
            PlatformSetting::class,
            fn (): PlatformSetting => PlatformSetting::singleton()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): PlatformSetting
    {
        $settings = PlatformSetting::singleton();
        $settings->update($data);
        $this->forgetCache();

        return $settings->refresh();
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
