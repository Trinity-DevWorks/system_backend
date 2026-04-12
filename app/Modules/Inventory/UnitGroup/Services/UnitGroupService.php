<?php

namespace App\Modules\Inventory\UnitGroup\Services;

use App\Modules\Inventory\UnitGroup\DTOs\UnitGroupData;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use Illuminate\Database\Eloquent\Collection;

class UnitGroupService
{
    public function list(): Collection
    {
        return UnitGroup::query()->orderBy('name')->get();
    }

    public function create(UnitGroupData $data): UnitGroup
    {
        return UnitGroup::query()->create($data->toArray());
    }

    public function update(UnitGroup $group, UnitGroupData $data): UnitGroup
    {
        $group->update($data->toArray());

        return $group->refresh();
    }

    public function delete(UnitGroup $group): void
    {
        if ($group->unitsOfMeasurement()->exists()) {
            abort(409, 'Cannot delete unit group while units of measurement exist.');
        }

        $group->delete();
    }

    public function units(UnitGroup $group): Collection
    {
        return $group->unitsOfMeasurement()->orderBy('name')->get();
    }
}
