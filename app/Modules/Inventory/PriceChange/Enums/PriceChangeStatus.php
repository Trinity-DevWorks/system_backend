<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Enums;

enum PriceChangeStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Applied = 'applied';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
