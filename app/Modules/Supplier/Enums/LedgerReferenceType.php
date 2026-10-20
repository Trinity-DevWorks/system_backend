<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Enums;

enum LedgerReferenceType: string
{
    case OpeningBalance = 'opening_balance';
    case PurchaseInvoice = 'purchase_invoice';
    case Payment = 'payment';
    case DebitNote = 'debit_note';
    case Refund = 'refund';
}
