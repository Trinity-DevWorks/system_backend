<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Enums;

enum PurchaseInvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
