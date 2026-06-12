<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Enums\ReplenishmentMethod;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class ItemWarehouseReplenishmentResponseData
{
    public static function fromModel(ItemWarehouseReplenishment $row): array
    {
        $row->loadMissing([
            'item:id,sku,name,base_uom_id,track_inventory,allow_purchase,is_active',
            'item.baseUom:id,code,name',
            'warehouse:id,name,shortcut_name,is_active',
        ]);

        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'warehouse_id' => $row->warehouse_id,
            'safety_stock_qty' => (string) $row->safety_stock_qty,
            'reorder_point_qty' => (string) $row->reorder_point_qty,
            'reorder_qty' => $row->reorder_qty !== null ? (string) $row->reorder_qty : null,
            'max_qty' => $row->max_qty !== null ? (string) $row->max_qty : null,
            'replenishment_method' => ReplenishmentMethod::forRule(
                $row->max_qty !== null ? (float) $row->max_qty : null,
            )->value,
            'lead_time_days' => $row->lead_time_days,
            'is_active' => (bool) $row->is_active,
            'item' => self::itemBrief($row->item),
            'warehouse' => self::warehouseBrief($row->warehouse),
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @param  Collection<int, ItemWarehouseReplenishment>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (ItemWarehouseReplenishment $row): array => self::fromModel($row))
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
            'allow_purchase' => (bool) $item->allow_purchase,
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
