<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use Illuminate\Support\Carbon;

/**
 * Renders {date}, {day}, {month}, {month_name}, {year}, {quarter} and {period}
 * in a template description. Unknown tokens are left as written.
 */
final class DescriptionPlaceholders
{
    public static function render(?string $text, Carbon $date): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $date = $date->copy();
        $locale = 'en';

        try {
            $locale = CompanySetting::current()->preferred_language->value;
        } catch (\Throwable) {
            $locale = 'en';
        }
        $monthName = $date->copy()->locale($locale)->translatedFormat('F');
        if ($monthName === '') {
            $monthName = $date->copy()->locale('en')->format('F');
        }

        $rendered = strtr($text, [
            '{date}' => $date->format('Y-m-d'),
            '{day}' => $date->format('d'),
            '{month}' => $date->format('m'),
            '{month_name}' => $monthName,
            '{year}' => $date->format('Y'),
            '{quarter}' => 'Q'.$date->quarter,
            '{period}' => $date->format('Y-m'),
        ]);

        return mb_substr($rendered, 0, 500);
    }
}
