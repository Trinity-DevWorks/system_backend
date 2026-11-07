<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Enums;

/**
 * Where receipts and charges of a landed cost come from.
 */
enum LandedCostSourceType: string
{
    /** Receipts. */
    case GoodsReceipt = 'goods_receipt';
    case PurchaseInvoice = 'purchase_invoice';

    /** Charges. */
    case PurchaseInvoiceLine = 'purchase_invoice_line';
    case ExpenseVoucherLine = 'expense_voucher_line';

    /**
     * @return list<string>
     */
    public static function receiptValues(): array
    {
        return [self::GoodsReceipt->value, self::PurchaseInvoice->value];
    }

    /**
     * @return list<string>
     */
    public static function chargeValues(): array
    {
        return [self::PurchaseInvoiceLine->value, self::ExpenseVoucherLine->value];
    }
}
