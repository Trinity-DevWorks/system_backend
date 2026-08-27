<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

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

    public function allows(float $quantity): bool
    {
        return match ($this) {
            self::Increase => $quantity > 0,
            self::Decrease => $quantity < 0,
            self::Both => $quantity != 0.0,
        };
    }
}
