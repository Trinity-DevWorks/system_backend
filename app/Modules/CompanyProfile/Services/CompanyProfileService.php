<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Services;

use App\Modules\CompanyProfile\DTOs\CompanyProfileData;
use App\Modules\CompanyProfile\Models\CompanyProfile;

class CompanyProfileService
{
    public function get(): CompanyProfile
    {
        return CompanyProfile::singleton()->load('logoAttachment');
    }

    public function update(CompanyProfileData $data): CompanyProfile
    {
        $profile = CompanyProfile::singleton();
        $profile->update($data->toArray());

        return $profile->refresh()->load('logoAttachment');
    }
}
