<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Support\StockPipelineQuantities;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class StockBalanceResponseData
{
    /**
     * @param  array{on_order_qty?: string, in_transit_in_qty?: string, in_transit_out_qty?: string, projected_qty?: string}  $pipeline
     */
    public static function fromModel(StockBalance $balance, array $pipeline = []): array
    {
        $balance->loadMissing([
            'item:id,item_code,name,base_uom_id,track_inventory,track_lots,is_active',
            'item.baseUom:id,code,name',
            'warehouse:id,name,shortcut_name,is_active',
            'lot:id,lot_number,expiry_date',
        ]);

        $lot = $balance->lot;
        $onHand = (string) $balance->quantity;
        $pipelineFields = StockPipelineQuantities::compose(
            $onHand,
            $pipeline['on_order_qty'] ?? '0',
            $pipeline['in_transit_in_qty'] ?? '0',
            $pipeline['in_transit_out_qty'] ?? '0',
        );

        return [
            'id' => $balance->id,
            'item_id' => $balance->item_id,
            'warehouse_id' => $balance->warehouse_id,
            'lot_id' => $balance->lot_id,
            'lot' => $lot ? [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'is_expired' => $lot->isExpired(),
            ] : null,
            'quantity' => $onHand,
            'on_order_qty' => $pipelineFields['on_order_qty'],
            'in_transit_in_qty' => $pipelineFields['in_transit_in_qty'],
            'in_transit_out_qty' => $pipelineFields['in_transit_out_qty'],
            'projected_qty' => $pipelineFields['projected_qty'],
            'unit_cost' => (string) $balance->unit_cost,
            'inventory_value' => (string) $balance->inventory_value,
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
            'item_code' => $item->item_code,
            'name' => $item->name,
            'track_inventory' => (bool) $item->track_inventory,
            'track_lots' => (bool) $item->track_lots,
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
