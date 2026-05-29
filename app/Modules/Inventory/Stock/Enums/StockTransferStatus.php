<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

enum StockTransferStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
