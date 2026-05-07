<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUnitOfMeasurement;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ItemUomService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function attach(Item $item, array $data): ItemUnitOfMeasurement
    {
        return DB::transaction(function () use ($item, $data): ItemUnitOfMeasurement {
            if (! $item->base_uom_id) {
                abort(422, 'Item must have a base unit of measurement before attaching alternates.', ['X-Error-Code' => 'ITEM_BASE_UOM_REQUIRED']);
            }

            $uom = UnitOfMeasurement::query()->findOrFail((int) $data['unit_of_measurement_id']);

            $this->assertSameUnitGroupAsBase($item, $uom);

            if (ItemUnitOfMeasurement::query()->where('item_id', $item->id)->where('unit_of_measurement_id', $uom->id)->exists()) {
                abort(422, 'This unit of measurement is already attached to the item.', ['X-Error-Code' => 'ITEM_UOM_ALREADY_ATTACHED']);
            }

            $isBase = (int) $uom->id === (int) $item->base_uom_id;
            $operation = $isBase ? 'multiply' : $data['operation'];
            $conversion = $isBase ? 1 : (float) $data['conversion'];

            if ($isBase && ($data['operation'] !== 'multiply' || (float) $data['conversion'] !== 1.0)) {
                abort(422, 'Base UOM must use operation multiply and conversion 1.', ['X-Error-Code' => 'ITEM_BASE_UOM_INVALID_CONVERSION']);
            }

            return ItemUnitOfMeasurement::query()->create([
                'item_id' => $item->id,
                'unit_of_measurement_id' => $uom->id,
                'operation' => $operation,
                'conversion' => $conversion,
                'price_1' => $data['price_1'] ?? null,
                'price_2' => $data['price_2'] ?? null,
                'price_3' => $data['price_3'] ?? null,
                'price_4' => $data['price_4'] ?? null,
                'price_5' => $data['price_5'] ?? null,
                'price_6' => $data['price_6'] ?? null,
                'gross_volume' => $data['gross_volume'] ?? null,
                'gross_weight' => $data['gross_weight'] ?? null,
                'net_volume' => $data['net_volume'] ?? null,
                'net_weight' => $data['net_weight'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePivot(Item $item, UnitOfMeasurement $unitOfMeasurement, array $data): ItemUnitOfMeasurement
    {
        return DB::transaction(function () use ($item, $unitOfMeasurement, $data): ItemUnitOfMeasurement {
            $pivot = ItemUnitOfMeasurement::query()
                ->where('item_id', $item->id)
                ->where('unit_of_measurement_id', $unitOfMeasurement->id)
                ->firstOrFail();

            $isBase = (int) $unitOfMeasurement->id === (int) $item->base_uom_id;

            if ($isBase) {
                if ($data['operation'] !== 'multiply' || (float) $data['conversion'] !== 1.0) {
                    abort(422, 'Base UOM must use operation multiply and conversion 1.', ['X-Error-Code' => 'ITEM_BASE_UOM_INVALID_CONVERSION']);
                }
            }

            $pivot->update([
                'operation' => $data['operation'],
                'conversion' => $data['conversion'],
                'price_1' => $data['price_1'] ?? null,
                'price_2' => $data['price_2'] ?? null,
                'price_3' => $data['price_3'] ?? null,
                'price_4' => $data['price_4'] ?? null,
                'price_5' => $data['price_5'] ?? null,
                'price_6' => $data['price_6'] ?? null,
                'gross_volume' => $data['gross_volume'] ?? null,
                'gross_weight' => $data['gross_weight'] ?? null,
                'net_volume' => $data['net_volume'] ?? null,
                'net_weight' => $data['net_weight'] ?? null,
            ]);

            return $pivot->refresh();
        });
    }

    public function detach(Item $item, UnitOfMeasurement $unitOfMeasurement): void
    {
        if ((int) $unitOfMeasurement->id === (int) $item->base_uom_id) {
            abort(422, 'Cannot detach the base unit of measurement.', ['X-Error-Code' => 'ITEM_BASE_UOM_DETACH_FORBIDDEN']);
        }

        ItemUnitOfMeasurement::query()
            ->where('item_id', $item->id)
            ->where('unit_of_measurement_id', $unitOfMeasurement->id)
            ->delete();
    }

    /**
     * @return Collection<int, ItemUnitOfMeasurement>
     */
    public function listForItem(Item $item): Collection
    {
        return ItemUnitOfMeasurement::query()
            ->where('item_id', $item->id)
            ->with('unitOfMeasurement:id,code,name,unit_group_id')
            ->orderBy('id')
            ->get();
    }

    private function assertSameUnitGroupAsBase(Item $item, UnitOfMeasurement $uom): void
    {
        $base = UnitOfMeasurement::query()->find($item->base_uom_id);
        if (! $base) {
            abort(422, 'Item base unit of measurement is missing.', ['X-Error-Code' => 'ITEM_BASE_UOM_MISSING']);
        }

        if ((int) $base->unit_group_id !== (int) $uom->unit_group_id) {
            abort(422, 'Unit of measurement must belong to the same unit group as the item base UOM.', ['X-Error-Code' => 'ITEM_UOM_UNIT_GROUP_MISMATCH']);
        }
    }
}
