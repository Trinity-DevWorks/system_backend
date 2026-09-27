<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * Mirrors InvoiceRegistry.Status. NotRegistered is represented as a missing record.
 */
enum InvoiceOnChainStatus: int
{
    case Registered = 1;
    case SupplierApproved = 2;
    case FullyApproved = 3;
}
