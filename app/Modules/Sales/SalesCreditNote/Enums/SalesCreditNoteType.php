<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Enums;

enum SalesCreditNoteType: string
{
    case Return = 'return';
    case Allowance = 'allowance';
}
