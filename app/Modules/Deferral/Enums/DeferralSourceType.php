<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Enums;

enum DeferralSourceType: string
{
    case Manual = 'manual';
    case ExpenseVoucher = 'expense_voucher';
    case PurchaseInvoice = 'purchase_invoice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
