<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemWarehouseReplenishmentService
{
    /**
     * @return Collection<int, ItemWarehouseReplenishment>
     */
    public function listForItem(Item $item): Collection
    {
        $this->assertReplenishableItem($item);

        return ItemWarehouseReplenishment::query()
            ->with([
                'item:id,sku,name,base_uom_id,track_inventory,allow_purchase,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ])
            ->where('item_id', $item->id)
            ->orderBy('warehouse_id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Item $item, array $data): ItemWarehouseReplenishment
    {
        $this->assertReplenishableItem($item);

        return DB::transaction(function () use ($item, $data): ItemWarehouseReplenishment {
            $warehouseId = (int) $data['warehouse_id'];
            $this->assertActiveWarehouse($warehouseId);

            if (ItemWarehouseReplenishment::query()
                ->where('item_id', $item->id)
                ->where('warehouse_id', $warehouseId)
                ->exists()) {
                abort(422, 'A replenishment rule already exists for this warehouse.', [
                    'X-Error-Code' => 'ITEM_WAREHOUSE_REPLENISHMENT_EXISTS',
                ]);
            }

            $safetyStock = (float) ($data['safety_stock_qty'] ?? 0);
            $reorderPoint = (float) $data['reorder_point_qty'];
            $maxQty = $this->nullableQty($data['max_qty'] ?? null);
            $this->assertThresholds($safetyStock, $reorderPoint, $maxQty);

            $row = ItemWarehouseReplenishment::query()->create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouseId,
                'safety_stock_qty' => $safetyStock,
                'reorder_point_qty' => $reorderPoint,
                'reorder_qty' => $this->nullableQty($data['reorder_qty'] ?? null),
                'max_qty' => $maxQty,
                'lead_time_days' => isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            return $row->load([
                'item:id,sku,name,base_uom_id,track_inventory,allow_purchase,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Item $item,
        ItemWarehouseReplenishment $replenishment,
        array $data,
    ): ItemWarehouseReplenishment {
        $this->assertBelongsToItem($item, $replenishment);
        $this->assertReplenishableItem($item);

        return DB::transaction(function () use ($item, $replenishment, $data): ItemWarehouseReplenishment {
            if (array_key_exists('warehouse_id', $data)) {
                $warehouseId = (int) $data['warehouse_id'];
                $this->assertActiveWarehouse($warehouseId);

                if (ItemWarehouseReplenishment::query()
                    ->where('item_id', $item->id)
                    ->where('warehouse_id', $warehouseId)
                    ->whereKeyNot($replenishment->id)
                    ->exists()) {
                    abort(422, 'A replenishment rule already exists for this warehouse.', [
                        'X-Error-Code' => 'ITEM_WAREHOUSE_REPLENISHMENT_EXISTS',
                    ]);
                }

                $replenishment->warehouse_id = $warehouseId;
            }

            $safetyStock = array_key_exists('safety_stock_qty', $data)
                ? (float) ($data['safety_stock_qty'] ?? 0)
                : (float) $replenishment->safety_stock_qty;
            $reorderPoint = array_key_exists('reorder_point_qty', $data)
                ? (float) $data['reorder_point_qty']
                : (float) $replenishment->reorder_point_qty;
            $maxQty = array_key_exists('max_qty', $data)
                ? $this->nullableQty($data['max_qty'])
                : ($replenishment->max_qty !== null ? (float) $replenishment->max_qty : null);

            $this->assertThresholds($safetyStock, $reorderPoint, $maxQty);

            if (array_key_exists('safety_stock_qty', $data)) {
                $replenishment->safety_stock_qty = $safetyStock;
            }
            if (array_key_exists('reorder_point_qty', $data)) {
                $replenishment->reorder_point_qty = $reorderPoint;
            }
            if (array_key_exists('reorder_qty', $data)) {
                $replenishment->reorder_qty = $this->nullableQty($data['reorder_qty']);
            }
            if (array_key_exists('max_qty', $data)) {
                $replenishment->max_qty = $maxQty;
            }
            if (array_key_exists('lead_time_days', $data)) {
                $replenishment->lead_time_days = $data['lead_time_days'] !== null
                    ? (int) $data['lead_time_days']
                    : null;
            }
            if (array_key_exists('is_active', $data)) {
                $replenishment->is_active = (bool) $data['is_active'];
            }

            $replenishment->save();

            return $replenishment->load([
                'item:id,sku,name,base_uom_id,track_inventory,allow_purchase,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ]);
        });
    }

    public function delete(Item $item, ItemWarehouseReplenishment $replenishment): void
    {
        $this->assertBelongsToItem($item, $replenishment);
        $replenishment->delete();
    }

    private function assertReplenishableItem(Item $item): void
    {
        if (! $item->track_inventory) {
            throw ValidationException::withMessages([
                'item_id' => ['Replenishment rules apply only to inventory-tracked items.'],
            ]);
        }

        if (! $item->allow_purchase) {
            throw ValidationException::withMessages([
                'item_id' => ['Replenishment rules apply only to purchasable items.'],
            ]);
        }
    }

    private function assertBelongsToItem(Item $item, ItemWarehouseReplenishment $replenishment): void
    {
        if ((int) $replenishment->item_id !== (int) $item->id) {
            abort(404);
        }
    }

    private function assertActiveWarehouse(int $warehouseId): void
    {
        $warehouse = Warehouse::query()->find($warehouseId);

        if (! $warehouse) {
            abort(404);
        }

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The selected warehouse is inactive.'],
            ]);
        }
    }

    private function assertThresholds(float $safetyStock, float $reorderPoint, ?float $maxQty): void
    {
        if ($safetyStock > $reorderPoint) {
            throw ValidationException::withMessages([
                'safety_stock_qty' => ['Safety stock cannot be greater than the reorder point.'],
            ]);
        }

        if ($maxQty !== null && $maxQty < $reorderPoint) {
            throw ValidationException::withMessages([
                'max_qty' => ['Max quantity cannot be less than the reorder point.'],
            ]);
        }
    }

    private function nullableQty(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
