<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Enums;

/**
 * How one charge is spread over the receipt lines.
 */
enum LandedCostMethod: string
{
    /** Expensive lines carry more (customs duty, insurance). */
    case Value = 'value';

    /** Each base unit carries the same share. */
    case Quantity = 'quantity';

    /** Item weight x quantity (freight). */
    case Weight = 'weight';

    /** Item volume x quantity (sea freight by cubic meter). */
    case Volume = 'volume';

    /** The user types each line's share. */
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
