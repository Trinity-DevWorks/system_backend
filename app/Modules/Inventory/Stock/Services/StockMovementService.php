<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    /**
     * Single write path: ledger row + balance snapshot (base UOM quantities only).
     */
    public function post(StockMovementData $data): StockMovement
    {
        if (bccomp($data->quantityDelta, '0', 6) === 0) {
            abort(422, 'Stock movement quantity cannot be zero.', ['X-Error-Code' => 'STOCK_MOVEMENT_ZERO_QUANTITY']);
        }

        return DB::transaction(function () use ($data): StockMovement {
            $item = Item::query()->findOrFail($data->itemId);
            $warehouse = Warehouse::query()->findOrFail($data->warehouseId);

            $this->assertStockableItem($item);
            $this->assertActiveWarehouse($warehouse);

            if ($data->itemUomId !== null) {
                $itemUom = ItemUom::query()
                    ->where('item_id', $item->id)
                    ->whereKey($data->itemUomId)
                    ->first();

                if (! $itemUom) {
                    abort(422, 'Item UOM does not belong to this item.', ['X-Error-Code' => 'STOCK_ITEM_UOM_MISMATCH']);
                }
            }

            $balance = StockBalance::query()
                ->where('item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                $balance = StockBalance::query()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => 0,
                ]);
                $balance = StockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
            }

            $current = (string) $balance->quantity;
            $newQuantity = bcadd($current, $data->quantityDelta, 6);

            if (bccomp($newQuantity, '0', 6) < 0) {
                abort(422, 'Insufficient stock for this movement.', ['X-Error-Code' => 'STOCK_INSUFFICIENT']);
            }

            $movement = StockMovement::query()->create($data->toArray());

            $balance->update(['quantity' => $newQuantity]);

            return $movement->load(['item.baseUom', 'warehouse', 'itemUom.uom', 'user']);
        });
    }

    private function assertStockableItem(Item $item): void
    {
        if (! $item->is_active) {
            abort(422, 'Cannot move stock for an inactive item.', ['X-Error-Code' => 'STOCK_ITEM_INACTIVE']);
        }

        if (! $item->track_inventory) {
            abort(422, 'This item does not track inventory.', ['X-Error-Code' => 'STOCK_ITEM_NOT_TRACKED']);
        }
    }

    private function assertActiveWarehouse(Warehouse $warehouse): void
    {
        if (! $warehouse->is_active) {
            abort(422, 'Cannot move stock in an inactive warehouse.', ['X-Error-Code' => 'STOCK_WAREHOUSE_INACTIVE']);
        }
    }
}
