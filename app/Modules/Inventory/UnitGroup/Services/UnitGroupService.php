<?php

namespace App\Modules\Inventory\UnitGroup\Services;

use App\Modules\Inventory\UnitGroup\DTOs\UnitGroupData;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;

class UnitGroupService
{
    private const CACHE_LIST = 'unit_groups.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            UnitGroup::class,
            fn (): Collection => UnitGroup::query()->orderBy('name')->get()
        );
    }

    public function create(UnitGroupData $data): UnitGroup
    {
        $model = UnitGroup::query()->create($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $model;
    }

    public function update(UnitGroup $group, UnitGroupData $data): UnitGroup
    {
        $group->update($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $group->refresh();
    }

    public function delete(UnitGroup $group): void
    {
        if ($group->unitsOfMeasurement()->exists()) {
            abort(409, 'Cannot delete unit group while units of measurement exist.', ['X-Error-Code' => 'UNIT_GROUP_DELETE_HAS_UNITS']);
        }

        $group->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    public function units(UnitGroup $group): Collection
    {
        return $group->unitsOfMeasurement()->orderBy('name')->get();
    }
}
