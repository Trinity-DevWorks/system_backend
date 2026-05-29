<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

enum StockMovementType: string
{
    case Adjustment = 'adjustment';
    case Opening = 'opening';
    case Sale = 'sale';
    case Purchase = 'purchase';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case ProductionIn = 'production_in';
    case ProductionOut = 'production_out';
    case BundleSale = 'bundle_sale';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
