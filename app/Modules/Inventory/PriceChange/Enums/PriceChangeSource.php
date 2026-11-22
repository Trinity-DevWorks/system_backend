<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Enums;

enum PriceChangeSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Bulk = 'bulk';
    case PriceChangeDoc = 'price_change_doc';
    case VariantGenerate = 'variant_generate';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
