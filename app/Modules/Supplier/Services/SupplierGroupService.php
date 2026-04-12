<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\Supplier\DTOs\SupplierGroupData;
use App\Modules\Supplier\Models\SupplierGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SupplierGroupService
{
    public function list(): Collection
    {
        return SupplierGroup::query()->orderBy('name')->get();
    }

    public function names(): Collection
    {
        return SupplierGroup::query()
            ->select(['id', 'name', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    public function create(SupplierGroupData $data): SupplierGroup
    {
        return DB::transaction(function () use ($data): SupplierGroup {
            return SupplierGroup::query()->create($data->toArray());
        });
    }

    public function update(SupplierGroup $group, SupplierGroupData $data): SupplierGroup
    {
        return DB::transaction(function () use ($group, $data): SupplierGroup {
            $group->update($data->toArray());

            return $group->refresh();
        });
    }

    public function delete(SupplierGroup $group): void
    {
        $group->delete();
    }
}
