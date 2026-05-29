<?php

namespace App\Modules\Inventory\UnitOfMeasurement\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\UnitOfMeasurement\DTOs\UnitOfMeasurementData;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;

class UnitOfMeasurementService
{
    private const CACHE_LIST = 'unit_of_measurements.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            UnitOfMeasurement::class,
            fn (): Collection => UnitOfMeasurement::query()->orderBy('name')->get()
        )->load('unitGroup:id,code,name,dimension_type');
    }

    public function create(UnitOfMeasurementData $data): UnitOfMeasurement
    {
        $uom = UnitOfMeasurement::query()->create($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $uom->load('unitGroup:id,code,name,dimension_type');
    }

    public function update(UnitOfMeasurement $uom, UnitOfMeasurementData $data): UnitOfMeasurement
    {
        $uom->update($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $uom->refresh()->load('unitGroup:id,code,name,dimension_type');
    }

    public function delete(UnitOfMeasurement $uom): void
    {
        if (Item::query()->where('base_uom_id', $uom->id)->exists()) {
            abort(409, 'Cannot delete unit of measurement: referenced by items.', ['X-Error-Code' => 'UOM_DELETE_REFERENCED_BY_ITEMS']);
        }

        if (ItemUom::query()->where('uom_id', $uom->id)->exists()) {
            abort(409, 'Cannot delete unit of measurement: referenced by item UOM conversions.', ['X-Error-Code' => 'UOM_DELETE_REFERENCED_BY_ITEM_CONVERSIONS']);
        }

        $uom->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
