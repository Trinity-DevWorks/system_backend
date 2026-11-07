<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Enums;

enum CashBankAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
