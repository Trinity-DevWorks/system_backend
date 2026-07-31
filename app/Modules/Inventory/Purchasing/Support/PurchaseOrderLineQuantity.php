<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;

final class PurchaseOrderLineQuantity
{
    /**
     * @return array{quantity:string,base_quantity:string,item_uom_id:?int}
     */
    public static function resolve(Item $item, float $quantity, ?int $itemUomId): array
    {
        if ($quantity <= 0) {
            abort(422, 'Purchase order line quantity must be greater than zero.', ['X-Error-Code' => 'PURCHASE_ORDER_LINE_INVALID_QUANTITY']);
        }

        $formattedQty = number_format($quantity, 6, '.', '');

        if ($itemUomId === null) {
            return [
                'quantity' => $formattedQty,
                'base_quantity' => $formattedQty,
                'item_uom_id' => null,
            ];
        }

        $itemUom = ItemUom::query()
            ->where('item_id', $item->id)
            ->whereKey($itemUomId)
            ->first();

        if (! $itemUom) {
            abort(422, 'Item UOM does not belong to this item.', ['X-Error-Code' => 'PURCHASE_ORDER_ITEM_UOM_MISMATCH']);
        }

        $baseQuantity = bcmul($formattedQty, (string) $itemUom->conversion_factor, 6);

        if (bccomp($baseQuantity, '0', 6) <= 0) {
            abort(422, 'Purchase order line base quantity must be greater than zero.', ['X-Error-Code' => 'PURCHASE_ORDER_LINE_INVALID_BASE_QUANTITY']);
        }

        return [
            'quantity' => $formattedQty,
            'base_quantity' => $baseQuantity,
            'item_uom_id' => (int) $itemUom->id,
        ];
    }
}
