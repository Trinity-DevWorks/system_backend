<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockCountLine;
use Illuminate\Support\Collection;

readonly class StockCountLineResponseData
{
    public static function fromModel(StockCountLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,is_active,track_inventory,track_lots,base_uom_id',
            'item.baseUom:id,code,name',
            'lot:id,lot_number,expiry_date',
        ]);

        $lot = $line->lot;

        return [
            'id' => $line->id,
            'stock_count_id' => $line->stock_count_id,
            'item_id' => $line->item_id,
            'theoretical_quantity' => (string) $line->theoretical_quantity,
            'counted_quantity' => (string) $line->counted_quantity,
            'variance_quantity' => (string) $line->variance_quantity,
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
        ];
    }

    /**
     * @param  Collection<int, StockCountLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (StockCountLine $line): array => self::fromModel($line))
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
            'base_uom' => $item->baseUom ? [
                'id' => $item->baseUom->id,
                'code' => $item->baseUom->code,
                'name' => $item->baseUom->name,
            ] : null,
        ];
    }
}
