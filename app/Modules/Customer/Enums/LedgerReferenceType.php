<?php

declare(strict_types=1);

namespace App\Modules\Customer\Enums;

enum LedgerReferenceType: string
{
    case Invoice = 'invoice';
    case Payment = 'payment';
    /** A payment taken with a sales invoice (no receipt); reference_id = the invoice. */
    case SalePayment = 'sale_payment';
    case CreditNote = 'credit_note';
    case Refund = 'refund';
    case FixedAssetDisposal = 'fixed_asset_disposal';
}
