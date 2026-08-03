<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Services;

use App\Modules\TenantSetting\DTOs\TenantSettingData;
use App\Modules\TenantSetting\Models\TenantSetting;

class TenantSettingService
{
    public function get(): TenantSetting
    {
        return TenantSetting::singleton()->load('primaryCurrency');
    }

    public function update(TenantSettingData $data): TenantSetting
    {
        $settings = TenantSetting::singleton();
        $settings->update($data->toArray());

        return $settings->refresh()->load('primaryCurrency');
    }
}
