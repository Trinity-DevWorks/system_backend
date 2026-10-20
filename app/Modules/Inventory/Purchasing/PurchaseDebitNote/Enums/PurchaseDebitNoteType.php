<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums;

enum PurchaseDebitNoteType: string
{
    case Return = 'return';
    case Allowance = 'allowance';
}
