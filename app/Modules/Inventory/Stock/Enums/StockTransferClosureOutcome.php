<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

enum StockTransferClosureOutcome: string
{
    case Return = 'return';
    case WriteOff = 'write_off';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
