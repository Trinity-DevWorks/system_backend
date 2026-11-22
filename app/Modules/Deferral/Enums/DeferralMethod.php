<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Enums;

enum DeferralMethod: string
{
    case StraightLineMonthly = 'straight_line_monthly';
    case DailyProrata = 'daily_prorata';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
