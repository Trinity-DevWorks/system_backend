<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUnitOfMeasurement;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ItemService
{
    public function list(): Collection
    {
        return Item::query()
            ->with(['baseUom:id,code,name,unit_group_id', 'purchaseUom:id,code,name,unit_group_id', 'salesUom:id,code,name,unit_group_id'])
            ->orderBy('name')
            ->get();
    }

    public function create(ItemData $data): Item
    {
        return DB::transaction(function () use ($data): Item {
            $item = Item::query()->create($data->toArray());

            if ($data->type === 'stockable' && $data->baseUomId) {
                $this->ensureBasePivot($item->id, $data->baseUomId);
            }

            return $item->load(['baseUom', 'purchaseUom', 'salesUom']);
        });
    }

    public function update(Item $item, ItemData $data): Item
    {
        return DB::transaction(function () use ($item, $data): Item {
            $oldBaseId = $item->base_uom_id;

            if ($data->type !== 'stockable') {
                ItemUnitOfMeasurement::query()->where('item_id', $item->id)->delete();
            }

            $item->update($data->toArray());
            $item->refresh();

            if ($data->type === 'stockable' && $data->baseUomId) {
                $newBase = UnitOfMeasurement::query()->findOrFail($data->baseUomId);
                $this->assertPivotsMatchBaseGroup($item, $newBase);

                if ($oldBaseId !== null && (int) $oldBaseId !== (int) $data->baseUomId) {
                    ItemUnitOfMeasurement::query()
                        ->where('item_id', $item->id)
                        ->where('unit_of_measurement_id', $oldBaseId)
                        ->delete();
                }

                $this->ensureBasePivot($item->id, $data->baseUomId);
            }

            return $item->load(['baseUom', 'purchaseUom', 'salesUom']);
        });
    }

    public function delete(Item $item): void
    {
        $item->delete();
    }

    private function ensureBasePivot(int $itemId, int $baseUomId): void
    {
        ItemUnitOfMeasurement::query()->updateOrCreate(
            [
                'item_id' => $itemId,
                'unit_of_measurement_id' => $baseUomId,
            ],
            [
                'operation' => 'multiply',
                'conversion' => 1,
            ]
        );
    }

    private function assertPivotsMatchBaseGroup(Item $item, UnitOfMeasurement $newBase): void
    {
        $pivots = ItemUnitOfMeasurement::query()
            ->where('item_id', $item->id)
            ->with('unitOfMeasurement:id,unit_group_id')
            ->get();

        foreach ($pivots as $pivot) {
            $uom = $pivot->unitOfMeasurement;
            if ($uom && (int) $uom->unit_group_id !== (int) $newBase->unit_group_id) {
                abort(422, 'Cannot change base UOM: existing alternate UOMs belong to a different unit group.', ['X-Error-Code' => 'ITEM_BASE_UOM_CHANGE_GROUP_MISMATCH']);
            }
        }
    }
}
