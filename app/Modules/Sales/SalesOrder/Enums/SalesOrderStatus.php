<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Enums;

enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyInvoiced = 'partially_invoiced';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';
    /** Confirmed order stopped early: the not-yet-invoiced remainder is no longer reserved. */
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
