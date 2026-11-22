<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Enums;

enum FixedAssetSource: string
{
    case PurchaseInvoiceLine = 'purchase_invoice_line';
    case Manual = 'manual';
}
