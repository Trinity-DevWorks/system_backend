<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Enums;

/**
 * What a party declares its blockchain address to be. The chain is checked on save:
 * a personal wallet has no contract code; a Safe has readable owners.
 */
enum WalletType: string
{
    case Wallet = 'wallet';
    case Safe = 'safe';
}
