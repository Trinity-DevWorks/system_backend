<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;

final class StockAdjustmentQuantity
{
    /**
     * Signed quantity delta in base UOM for ledger posting.
     */
    public static function resolveBaseDelta(Item $item, float $quantityDelta, ?int $itemUomId): string
    {
        if ($quantityDelta == 0.0) {
            abort(422, 'Stock movement quantity cannot be zero.', ['X-Error-Code' => 'STOCK_MOVEMENT_ZERO_QUANTITY']);
        }

        if ($itemUomId === null) {
            return number_format($quantityDelta, 6, '.', '');
        }

        $itemUom = ItemUom::query()
            ->where('item_id', $item->id)
            ->whereKey($itemUomId)
            ->first();

        if (! $itemUom) {
            abort(422, 'Item UOM does not belong to this item.', ['X-Error-Code' => 'STOCK_ITEM_UOM_MISMATCH']);
        }

        $absInput = number_format(abs($quantityDelta), 6, '.', '');
        $absBase = bcmul($absInput, (string) $itemUom->conversion_factor, 6);

        if (bccomp($absBase, '0', 6) === 0) {
            abort(422, 'Adjustment base quantity cannot be zero.', ['X-Error-Code' => 'STOCK_ADJUSTMENT_ZERO_BASE_QUANTITY']);
        }

        if ($quantityDelta < 0) {
            return bcmul($absBase, '-1', 6);
        }

        return $absBase;
    }
}
