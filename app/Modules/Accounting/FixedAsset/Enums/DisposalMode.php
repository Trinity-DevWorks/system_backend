<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Enums;

enum DisposalMode: string
{
    case Scrap = 'scrap';
    case SaleOnAccount = 'sale_on_account';
    case SaleCash = 'sale_cash';
}
