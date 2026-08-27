<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Services;

use App\Modules\CompanySetting\DTOs\CompanySettingData;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Support\TenantReferenceCache;

class CompanySettingService
{
    public const CACHE_KEY = 'company_settings.singleton';

    public function get(): CompanySetting
    {
        $settings = TenantReferenceCache::rememberModel(
            self::CACHE_KEY,
            CompanySetting::class,
            fn (): CompanySetting => CompanySetting::singleton()
        );

        return $settings->loadMissing('primaryCurrency');
    }

    public function update(CompanySettingData $data): CompanySetting
    {
        $settings = CompanySetting::singleton();
        $settings->update($data->toArray());
        $this->forgetCache();

        return $settings->refresh()->load('primaryCurrency');
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
