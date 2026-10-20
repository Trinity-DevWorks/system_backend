<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Enums;

enum SalesQuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
