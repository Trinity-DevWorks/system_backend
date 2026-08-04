<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Services;

use App\Modules\TenantSetting\DTOs\TenantSettingData;
use App\Modules\TenantSetting\Models\TenantSetting;
use App\Support\TenantReferenceCache;

class TenantSettingService
{
    public const CACHE_KEY = 'tenant_settings.singleton';

    public function get(): TenantSetting
    {
        $settings = TenantReferenceCache::rememberModel(
            self::CACHE_KEY,
            TenantSetting::class,
            fn (): TenantSetting => TenantSetting::singleton()
        );

        return $settings->loadMissing('primaryCurrency');
    }

    public function update(TenantSettingData $data): TenantSetting
    {
        $settings = TenantSetting::singleton();
        $settings->update($data->toArray());
        $this->forgetCache();

        return $settings->refresh()->load('primaryCurrency');
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
