<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Support\Decimal;

final class StockAdjustmentQuantity
{
    /**
     * Signed quantity delta in base UOM for ledger posting.
     */
    public static function resolveBaseDelta(Item $item, mixed $quantityDelta, ?int $itemUomId): string
    {
        $quantityDelta = Decimal::round($quantityDelta, 6);
        if (Decimal::isZero($quantityDelta)) {
            abort(422, 'Stock movement quantity cannot be zero.', ['X-Error-Code' => 'STOCK_MOVEMENT_ZERO_QUANTITY']);
        }

        if ($itemUomId === null) {
            return $quantityDelta;
        }

        $itemUom = ItemUom::query()
            ->where('item_id', $item->id)
            ->whereKey($itemUomId)
            ->first();

        if (! $itemUom) {
            abort(422, 'Item UOM does not belong to this item.', ['X-Error-Code' => 'STOCK_ITEM_UOM_MISMATCH']);
        }

        $absInput = Decimal::abs($quantityDelta);
        $absBase = bcmul($absInput, (string) $itemUom->conversion_factor, 6);

        if (bccomp($absBase, '0', 6) === 0) {
            abort(422, 'Adjustment base quantity cannot be zero.', ['X-Error-Code' => 'STOCK_ADJUSTMENT_ZERO_BASE_QUANTITY']);
        }

        if (Decimal::isNegative($quantityDelta)) {
            return bcmul($absBase, '-1', 6);
        }

        return $absBase;
    }

    /**
     * Unit cost converted to base UOM (cost per selected UOM / conversion factor).
     */
    public static function resolveBaseUnitCost(Item $item, mixed $unitCost, ?int $itemUomId): ?string
    {
        if ($unitCost === null || $unitCost === '') {
            return null;
        }

        $entered = InventoryCostingMath::unitCost($unitCost);
        if (bccomp($entered, '0', InventoryCostingMath::UNIT_COST_SCALE) < 0) {
            abort(422, 'Unit cost cannot be negative.', ['X-Error-Code' => 'STOCK_UNIT_COST_INVALID']);
        }

        if ($itemUomId === null) {
            return $entered;
        }

        $itemUom = ItemUom::query()
            ->where('item_id', $item->id)
            ->whereKey($itemUomId)
            ->first();

        if (! $itemUom) {
            abort(422, 'Item UOM does not belong to this item.', ['X-Error-Code' => 'STOCK_ITEM_UOM_MISMATCH']);
        }

        $factor = (string) $itemUom->conversion_factor;
        if (bccomp($factor, '0', 6) <= 0) {
            abort(422, 'Item UOM conversion factor is invalid.', ['X-Error-Code' => 'STOCK_ITEM_UOM_MISMATCH']);
        }

        return InventoryCostingMath::unitCost(bcdiv($entered, $factor, InventoryCostingMath::UNIT_COST_SCALE + 6));
    }
}
