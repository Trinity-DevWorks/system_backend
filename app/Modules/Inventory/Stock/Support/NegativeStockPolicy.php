<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Warehouse\Models\Warehouse;

/**
 * May this item's stock go below zero in this warehouse?
 * Item "never" always wins; else item "allow"; else the warehouse (allow / block); else the company setting.
 * Items with serials or lots never go negative (each unit or lot must exist).
 */
final class NegativeStockPolicy
{
    public const ITEM_ALLOW = 'allow';

    public const ITEM_NEVER = 'never';

    public const WAREHOUSE_ALLOW = 'allow';

    public const WAREHOUSE_BLOCK = 'block';

    public static function allows(Item $item, Warehouse|int $warehouse): bool
    {
        if ((bool) $item->track_serials || (bool) $item->track_lots) {
            return false;
        }
        $itemRule = $item->getAttribute('negative_stock');
        if ($itemRule === self::ITEM_NEVER) {
            return false;
        }
        if ($itemRule === self::ITEM_ALLOW) {
            return true;
        }

        $warehouseRule = $warehouse instanceof Warehouse
            ? $warehouse->getAttribute('negative_stock')
            : Warehouse::query()->whereKey($warehouse)->value('negative_stock');
        if ($warehouseRule === self::WAREHOUSE_ALLOW) {
            return true;
        }
        if ($warehouseRule === self::WAREHOUSE_BLOCK) {
            return false;
        }

        return CompanySetting::current()->allowsNegativeStock();
    }
}
