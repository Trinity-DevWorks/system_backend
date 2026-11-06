<?php

declare(strict_types=1);

namespace App\Modules\Customer\Enums;

enum LedgerReferenceType: string
{
    case Invoice = 'invoice';
    case Payment = 'payment';
    case CreditNote = 'credit_note';
    case Refund = 'refund';
}
