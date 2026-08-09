<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

enum WarehouseType: string
{
    case Branch = 'branch';
    case Central = 'central';
    case Distribution = 'distribution';

    public function requiresBranch(): bool
    {
        return $this === self::Branch;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
