<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Notification\Services\InstantLotExpiryNotifier;
use App\Modules\Notification\Services\InstantLowStockNotifier;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly InstantLowStockNotifier $instantLowStockNotifier,
        private readonly InstantLotExpiryNotifier $instantLotExpiryNotifier,
        private readonly InventoryCostingService $inventoryCostingService,
    ) {}

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
            $this->warehouseService->assertVisible($warehouse);

            if ($data->itemUomId !== null) {
                $itemUom = ItemUom::query()
                    ->where('item_id', $item->id)
                    ->whereKey($data->itemUomId)
                    ->first();

                if (! $itemUom) {
                    abort(422, 'Item UOM does not belong to this item.', ['X-Error-Code' => 'STOCK_ITEM_UOM_MISMATCH']);
                }
            }

            $warehouseOnHand = StockBalance::onHandForWarehouse($item->id, $warehouse->id);
            $balance = StockBalance::lockRow($item->id, $warehouse->id, $data->lotId);

            $current = (string) $balance->quantity;
            $newQuantity = bcadd($current, $data->quantityDelta, 6);

            if (
                bccomp($newQuantity, '0', 6) < 0
                && ! CompanySetting::current()->allowsNegativeStock()
            ) {
                abort(422, 'Insufficient stock for this movement.', ['X-Error-Code' => 'STOCK_INSUFFICIENT']);
            }

            $movement = StockMovement::query()->create($data->toArray());

            $costing = $this->inventoryCostingService->apply(
                $item,
                $balance,
                $data->quantityDelta,
                $data->unitCost,
                (int) $movement->id,
            );

            $movement->update([
                'unit_cost' => $costing['unit_cost'],
                'value_delta' => $costing['value_delta'],
            ]);

            $balance->update(['quantity' => $newQuantity]);

            $this->instantLowStockNotifier->afterBalanceChanged(
                $item,
                $warehouse,
                $warehouseOnHand,
                bcadd($warehouseOnHand, $data->quantityDelta, 6),
            );

            if ($data->lotId !== null && bccomp($data->quantityDelta, '0', 6) > 0) {
                $this->instantLotExpiryNotifier->afterInboundLot(
                    $item,
                    $warehouse,
                    $data->lotId,
                    $newQuantity,
                );
            }

            return $movement->load(['item.baseUom', 'warehouse', 'itemUom.uom', 'user', 'lot']);
        });
    }

    /**
     * Post the opposite of every movement for a document, newest first.
     * Caller must already be inside a database transaction.
     */
    public function reverseReference(string $referenceType, string $referenceId, ?string $userId): void
    {
        $movements = StockMovement::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($movements as $original) {
            $this->reverseMovement($original, $userId);
        }
    }

    private function reverseMovement(StockMovement $original, ?string $userId): void
    {
        $originalDelta = (string) $original->quantity_delta;
        if (bccomp($originalDelta, '0', 6) === 0) {
            return;
        }

        $item = Item::query()->findOrFail($original->item_id);
        $warehouse = Warehouse::query()->findOrFail($original->warehouse_id);
        $this->warehouseService->assertVisible($warehouse);

        $reverseDelta = bcmul($originalDelta, '-1', 6);
        $warehouseOnHand = StockBalance::onHandForWarehouse((string) $item->id, (int) $warehouse->id);
        $balance = StockBalance::lockRow((string) $item->id, (int) $warehouse->id, $original->lot_id !== null ? (int) $original->lot_id : null);

        $newQuantity = bcadd((string) $balance->quantity, $reverseDelta, 6);
        if (
            bccomp($newQuantity, '0', 6) < 0
            && ! CompanySetting::current()->allowsNegativeStock()
        ) {
            abort(422, 'Insufficient stock to reverse this document.', ['X-Error-Code' => 'STOCK_INSUFFICIENT']);
        }

        $note = trim((string) ($original->notes ?? ''));
        $reverse = StockMovement::query()->create([
            'item_id' => $original->item_id,
            'warehouse_id' => $original->warehouse_id,
            'lot_id' => $original->lot_id,
            'quantity_delta' => $reverseDelta,
            'unit_cost' => $original->unit_cost,
            'value_delta' => bcmul((string) ($original->value_delta ?? '0'), '-1', 4),
            'type' => $original->type,
            'reference_type' => $original->reference_type,
            'reference_id' => $original->reference_id,
            'item_uom_id' => $original->item_uom_id,
            'notes' => $note !== '' ? 'Reverse — '.$note : 'Reverse',
            'user_id' => $userId,
        ]);

        $this->inventoryCostingService->unwind(
            $item,
            $balance,
            $originalDelta,
            (string) ($original->value_delta ?? '0'),
            $original->unit_cost !== null ? (string) $original->unit_cost : null,
            (int) $original->id,
            (int) $reverse->id,
        );

        $balance->update(['quantity' => $newQuantity]);

        $this->instantLowStockNotifier->afterBalanceChanged(
            $item,
            $warehouse,
            $warehouseOnHand,
            bcadd($warehouseOnHand, $reverseDelta, 6),
        );
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
