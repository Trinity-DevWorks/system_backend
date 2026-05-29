<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use Illuminate\Support\Collection;

readonly class StockTransferLineResponseData
{
    public static function fromModel(StockTransferLine $line): array
    {
        $line->loadMissing([
            'item:id,sku,name,is_active',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
        ]);

        return [
            'id' => $line->id,
            'stock_transfer_id' => $line->stock_transfer_id,
            'item_id' => $line->item_id,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'item_uom_id' => $line->item_uom_id,
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
     * @param  Collection<int, StockTransferLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (StockTransferLine $line): array => self::fromModel($line))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,sku:string,name:string,is_active:bool}|null
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
            'is_active' => (bool) $item->is_active,
        ];
    }
}
