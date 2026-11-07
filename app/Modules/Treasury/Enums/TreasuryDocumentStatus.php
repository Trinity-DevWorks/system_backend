<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Enums;

enum TreasuryDocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
