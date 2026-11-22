<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Enums;

enum DeferralPostingDay: string
{
    case PeriodEnd = 'period_end';
    case PeriodStart = 'period_start';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
