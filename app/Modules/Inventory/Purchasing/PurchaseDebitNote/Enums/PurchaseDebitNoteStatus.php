<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums;

enum PurchaseDebitNoteStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
