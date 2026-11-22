<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Enums;

enum RateMode: string
{
    case Current = 'current';
    case Fixed = 'fixed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
