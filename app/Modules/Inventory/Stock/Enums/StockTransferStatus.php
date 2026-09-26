<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

enum StockTransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<self>
     */
    public static function receivable(): array
    {
        return [self::InTransit, self::PartiallyReceived];
    }
}
