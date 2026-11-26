<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Enums;

enum PriceField: string
{
    case Selling = 'selling_price';
    case Cost = 'cost_price';
    case PriceList = 'price_list_price';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function itemUomValues(): array
    {
        return [
            self::Selling->value,
            self::Cost->value,
        ];
    }

    public function isSelling(): bool
    {
        return $this !== self::Cost;
    }
}
