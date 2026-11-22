<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use Illuminate\Support\Carbon;

/**
 * The tenant's local calendar date. Schedulers and "today" checks use this
 * instead of the server timezone.
 */
final class TenantClock
{
    public static function today(): Carbon
    {
        $timezone = (string) config('app.timezone');

        try {
            $configured = CompanySetting::current()->timezone;
            if (is_string($configured) && $configured !== '') {
                $timezone = $configured;
            }
        } catch (\Throwable) {
            // Fall back to the application timezone when settings are unavailable.
        }

        return Carbon::now($timezone)->startOfDay();
    }
}
