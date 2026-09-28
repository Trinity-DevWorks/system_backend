<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

enum InvoiceChainCheckStatus: string
{
    case Consistent = 'consistent';
    case Issues = 'issues';
    case Failed = 'failed';
}
