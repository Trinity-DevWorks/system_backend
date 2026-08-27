<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockAdjustmentLine;
use Illuminate\Support\Collection;

readonly class StockAdjustmentLineResponseData
{
    public static function fromModel(StockAdjustmentLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,is_active,track_inventory,track_lots',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
            'lot:id,lot_number,expiry_date',
        ]);

        $lot = $line->lot;

        return [
            'id' => $line->id,
            'stock_adjustment_id' => $line->stock_adjustment_id,
            'item_id' => $line->item_id,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'item_uom_id' => $line->item_uom_id,
            'unit_cost' => $line->unit_cost !== null ? (string) $line->unit_cost : null,
            'lot_id' => $line->lot_id,
            'lot' => $lot ? [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'is_expired' => $lot->isExpired(),
            ] : null,
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
        ];
    }

    /**
     * @param  Collection<int, StockAdjustmentLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (StockAdjustmentLine $line): array => self::fromModel($line))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
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
            'track_inventory' => (bool) $item->track_inventory,
            'track_lots' => (bool) $item->track_lots,
        ];
    }
}
