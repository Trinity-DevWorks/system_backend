<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Enums;

enum DeferralLineStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
