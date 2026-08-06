<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Services;

use App\Modules\CompanyProfile\DTOs\CompanyProfileData;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Support\TenantReferenceCache;

class CompanyProfileService
{
    public const CACHE_KEY = 'company_profile.singleton';

    public function get(): CompanyProfile
    {
        $profile = TenantReferenceCache::rememberModel(
            self::CACHE_KEY,
            CompanyProfile::class,
            fn (): CompanyProfile => CompanyProfile::singleton()
        );

        return $profile->loadMissing('logoAttachment');
    }

    public function update(CompanyProfileData $data): CompanyProfile
    {
        $profile = CompanyProfile::singleton();
        $profile->update($data->toArray());
        $this->forgetCache();

        return $profile->refresh()->load('logoAttachment');
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
