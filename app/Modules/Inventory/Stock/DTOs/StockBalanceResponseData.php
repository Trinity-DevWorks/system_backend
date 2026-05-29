<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class StockBalanceResponseData
{
    public static function fromModel(StockBalance $balance): array
    {
        $balance->loadMissing([
            'item:id,sku,name,base_uom_id,track_inventory,is_active',
            'item.baseUom:id,code,name',
            'warehouse:id,name,shortcut_name,is_active',
        ]);

        return [
            'id' => $balance->id,
            'item_id' => $balance->item_id,
            'warehouse_id' => $balance->warehouse_id,
            'quantity' => (string) $balance->quantity,
            'item' => self::itemBrief($balance->item),
            'warehouse' => self::warehouseBrief($balance->warehouse),
            'updated_at' => (string) $balance->updated_at,
        ];
    }

    /**
     * @param  Collection<int, StockBalance>  $balances
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $balances): array
    {
        return $balances
            ->map(fn (StockBalance $balance): array => self::fromModel($balance))
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

        $item->loadMissing('baseUom:id,code,name');

        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'track_inventory' => (bool) $item->track_inventory,
            'is_active' => (bool) $item->is_active,
            'base_uom' => $item->baseUom ? [
                'id' => $item->baseUom->id,
                'code' => $item->baseUom->code,
                'name' => $item->baseUom->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function warehouseBrief(?Warehouse $warehouse): ?array
    {
        if (! $warehouse) {
            return null;
        }

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'shortcut_name' => $warehouse->shortcut_name,
            'is_active' => (bool) $warehouse->is_active,
        ];
    }
}
