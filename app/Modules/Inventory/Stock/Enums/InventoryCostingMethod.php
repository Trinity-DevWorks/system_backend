<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Enums;

enum InventoryCostingMethod: string
{
    /** Inventory valued at the item's standard (catalog) cost. */
    case Standard = 'standard';

    /** Consume oldest receipt layers first. */
    case Fifo = 'fifo';

    /** Blend inbound cost into the on-hand average. */
    case MovingAverage = 'moving_average';

    /** Receipt layers at the document's actual cost; consume oldest unless a cost is supplied. */
    case Actual = 'actual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function usesLayers(): bool
    {
        return $this === self::Fifo || $this === self::Actual;
    }
}
