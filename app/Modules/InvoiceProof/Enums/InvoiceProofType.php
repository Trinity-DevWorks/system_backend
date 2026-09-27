<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * Invoice kind stored on a proof. Independent of ERP module names so the same
 * canonical JSON can represent a sales invoice or a future purchase invoice.
 */
enum InvoiceProofType: string
{
    case Sales = 'sales';
    case Purchase = 'purchase';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
