<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * Whether a verifier row is written on InvoiceRegistry for the company Safe.
 * Removing rows are deleted once the registrar revokes them on chain.
 */
enum InvoiceVerifierChainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Removing = 'removing';
}
