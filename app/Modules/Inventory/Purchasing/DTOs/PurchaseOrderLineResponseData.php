<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use Illuminate\Support\Collection;

readonly class PurchaseOrderLineResponseData
{
    public static function fromModel(PurchaseOrderLine $line): array
    {
        $line->loadMissing([
            'item:id,sku,name,item_code,is_active,allow_purchase',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
        ]);

        $orderedQty = (string) $line->quantity;
        $receivedQty = (string) $line->received_quantity;
        $openQty = bcsub($orderedQty, $receivedQty, 6);
        if (bccomp($openQty, '0', 6) < 0) {
            $openQty = '0.000000';
        }

        return [
            'id' => $line->id,
            'purchase_order_id' => $line->purchase_order_id,
            'item_id' => $line->item_id,
            'quantity' => $orderedQty,
            'base_quantity' => (string) $line->base_quantity,
            'received_quantity' => $receivedQty,
            'received_base_quantity' => (string) $line->received_base_quantity,
            'open_quantity' => $openQty,
            'item_uom_id' => $line->item_uom_id,
            'unit_price' => $line->unit_price !== null ? (string) $line->unit_price : null,
            'line_total' => self::lineTotal($line->unit_price, $orderedQty),
            'notes' => $line->notes,
            'item' => self::itemBrief($line->item),
            'item_uom' => $line->itemUom ? [
                'id' => $line->itemUom->id,
                'conversion_factor' => (string) $line->itemUom->conversion_factor,
                'uom' => $line->itemUom->uom ? [
                    'id' => $line->itemUom->uom->id,
                    'code' => $line->itemUom->uom->code,
                    'name' => $line->itemUom->uom->name,
                ] : null,
            ] : null,
            'created_at' => (string) $line->created_at,
            'updated_at' => (string) $line->updated_at,
        ];
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (PurchaseOrderLine $line): array => self::fromModel($line))
            ->values()
            ->all();
    }

    /**
     * @return array{id:string,sku:string,name:string,item_code:?string,is_active:bool,allow_purchase:bool}|null
     */
    private static function itemBrief(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }

        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'item_code' => $item->item_code,
            'is_active' => (bool) $item->is_active,
            'allow_purchase' => (bool) $item->allow_purchase,
        ];
    }

    private static function lineTotal(mixed $unitPrice, string $quantity): ?string
    {
        if ($unitPrice === null) {
            return null;
        }

        return bcmul((string) $unitPrice, $quantity, 4);
    }
}
