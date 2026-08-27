<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Warehouse\Models\Warehouse;

readonly class InventoryLotResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromBalance(StockBalance $balance): array
    {
        $balance->loadMissing([
            'item:id,item_code,name',
            'warehouse:id,name,shortcut_name,is_active',
            'lot:id,lot_number,expiry_date,item_id',
        ]);

        $lot = $balance->lot;

        return [
            'id' => $lot?->id ?? $balance->lot_id,
            'balance_id' => $balance->id,
            'item_id' => $balance->item_id,
            'warehouse_id' => $balance->warehouse_id,
            'lot_number' => $lot?->lot_number,
            'expiry_date' => $lot?->expiry_date?->toDateString(),
            'is_expired' => $lot?->isExpired() ?? false,
            'quantity' => number_format((float) $balance->quantity, 6, '.', ''),
            'item' => self::itemBrief($balance->item),
            'warehouse' => self::warehouseBrief($balance->warehouse),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromModel(InventoryLot $lot): array
    {
        $lot->loadMissing(['item:id,item_code,name']);

        $quantity = $lot->getAttribute('on_hand_quantity');
        if ($quantity === null) {
            $quantity = '0.000000';
        }

        return [
            'id' => $lot->id,
            'item_id' => $lot->item_id,
            'lot_number' => $lot->lot_number,
            'expiry_date' => $lot->expiry_date?->toDateString(),
            'is_expired' => $lot->isExpired(),
            'quantity' => is_string($quantity)
                ? $quantity
                : number_format((float) $quantity, 6, '.', ''),
            'item' => self::itemBrief($lot->item),
            'warehouse' => null,
        ];
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
