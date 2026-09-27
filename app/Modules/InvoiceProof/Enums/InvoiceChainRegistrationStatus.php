<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

enum InvoiceChainRegistrationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
