<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use App\Modules\Inventory\Stock\Support\StockTransferLineQuantity;
use Illuminate\Support\Collection;

readonly class StockTransferLineResponseData
{
    public static function fromModel(StockTransferLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,is_active,track_lots',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
            'lot:id,lot_number,expiry_date',
        ]);

        $lot = $line->lot;

        return [
            'id' => $line->id,
            'stock_transfer_id' => $line->stock_transfer_id,
            'item_id' => $line->item_id,
            'lot_id' => $line->lot_id,
            'lot' => $lot ? [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'is_expired' => $lot->isExpired(),
            ] : null,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'received_quantity' => (string) $line->received_quantity,
            'received_base_quantity' => (string) $line->received_base_quantity,
            'returned_quantity' => (string) $line->returned_quantity,
            'returned_base_quantity' => (string) $line->returned_base_quantity,
            'written_off_quantity' => (string) $line->written_off_quantity,
            'written_off_base_quantity' => (string) $line->written_off_base_quantity,
            'open_quantity' => StockTransferLineQuantity::openQuantity($line),
            'open_base_quantity' => StockTransferLineQuantity::openBaseQuantity($line),
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
     * @return array{id:string,item_code:?string,name:string,is_active:bool}|null
     */
    private static function itemBrief(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }

        return [
            'id' => $item->id,
            'item_code' => $item->item_code,
            'name' => $item->name,
            'is_active' => (bool) $item->is_active,
            'track_lots' => (bool) $item->track_lots,
        ];
    }
}
