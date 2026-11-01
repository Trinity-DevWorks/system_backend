<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

use App\Support\Decimal;

enum StockAdjustmentReasonDirection: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
    case Both = 'both';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function allows(mixed $quantity): bool
    {
        return match ($this) {
            self::Increase => Decimal::isPositive($quantity),
            self::Decrease => Decimal::isNegative($quantity),
            self::Both => ! Decimal::isZero($quantity),
        };
    }
}
