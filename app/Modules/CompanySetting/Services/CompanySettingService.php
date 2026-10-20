<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Services;

use App\Modules\Accounting\Enums\FiscalYearStatus;
use App\Modules\Accounting\Models\FiscalYear;
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
        if (
            $data->fiscalYearStartMonth !== (int) ($settings->fiscal_year_start_month ?? 1)
            && FiscalYear::query()->where('status', FiscalYearStatus::Closed)->exists()
        ) {
            abort(422, 'The fiscal year start cannot change after a year has been closed.', [
                'X-Error-Code' => 'FISCAL_YEAR_START_LOCKED',
            ]);
        }

        $settings->update($data->toArray());
        $this->forgetCache();

        return $settings->refresh()->load('primaryCurrency');
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
