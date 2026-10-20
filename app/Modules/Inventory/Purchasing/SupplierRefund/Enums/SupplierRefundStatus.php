<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierRefund\Enums;

enum SupplierRefundStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
