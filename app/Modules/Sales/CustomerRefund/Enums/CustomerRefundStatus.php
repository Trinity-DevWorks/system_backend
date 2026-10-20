<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerRefund\Enums;

enum CustomerRefundStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
