<?php

declare(strict_types=1);

namespace App\Modules\Customer\Enums;

enum LedgerReferenceType: string
{
    case OpeningBalance = 'opening_balance';
    case Invoice = 'invoice';
    case Payment = 'payment';
}
