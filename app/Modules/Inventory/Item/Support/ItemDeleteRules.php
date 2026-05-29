<?php

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\RecipeItem;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use App\Modules\Supplier\Models\SupplierItem;

final class ItemDeleteRules
{
    public static function assertDeletable(Item $item): void
    {
        if (SupplierItem::query()->where('item_id', $item->id)->exists()) {
            abort(409, 'Cannot delete item: linked to one or more suppliers.', ['X-Error-Code' => 'ITEM_DELETE_REFERENCED_BY_SUPPLIERS']);
        }

        if (
            StockBalance::query()->where('item_id', $item->id)->exists()
            || StockMovement::query()->where('item_id', $item->id)->exists()
            || StockTransferLine::query()->where('item_id', $item->id)->exists()
        ) {
            abort(409, 'Cannot delete item: stock records exist.', ['X-Error-Code' => 'ITEM_DELETE_REFERENCED_BY_STOCK']);
        }

        if (RecipeItem::query()->where('item_id', $item->id)->exists()) {
            abort(409, 'Cannot delete item: used as a recipe ingredient.', ['X-Error-Code' => 'ITEM_DELETE_REFERENCED_BY_RECIPE']);
        }

        if (BundleItem::query()->where('child_item_id', $item->id)->exists()) {
            abort(409, 'Cannot delete item: used as a bundle component.', ['X-Error-Code' => 'ITEM_DELETE_REFERENCED_BY_BUNDLE']);
        }
    }
}
