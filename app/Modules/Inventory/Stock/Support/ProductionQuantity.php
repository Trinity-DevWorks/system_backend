<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Support\ItemUomValidation;

final class ProductionQuantity
{
    public static function itemUomIdForCatalogUom(Item $item, int $uomId): ?int
    {
        ItemUomValidation::assertUomAllowedForItem($item, $uomId);

        $row = ItemUom::query()
            ->where('item_id', $item->id)
            ->where('uom_id', $uomId)
            ->first();

        if ($row) {
            return (int) $row->id;
        }

        if ((int) $item->base_uom_id === $uomId) {
            return null;
        }

        abort(422, 'UOM is not configured for this item.', [
            'X-Error-Code' => 'ITEM_UOM_NOT_CONFIGURED',
        ]);
    }
}
