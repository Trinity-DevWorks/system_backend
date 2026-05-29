<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;

final class ItemUomValidation
{
    public static function assertUomAllowedForItem(Item $item, int $uomId): void
    {
        if ((int) $item->base_uom_id === $uomId) {
            return;
        }

        $allowed = ItemUom::query()
            ->where('item_id', $item->id)
            ->where('uom_id', $uomId)
            ->exists();

        if (! $allowed) {
            abort(422, 'UOM is not configured for this item.', ['X-Error-Code' => 'ITEM_UOM_NOT_CONFIGURED']);
        }
    }
}
