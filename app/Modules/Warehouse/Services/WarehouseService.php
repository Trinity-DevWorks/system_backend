<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\DTOs\WarehouseData;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function list(): Collection
    {
        return Warehouse::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function names(): Collection
    {
        return Warehouse::query()
            ->select(['id', 'name', 'shortcut_name', 'is_active', 'is_default', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    public function create(WarehouseData $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            if ($data->isDefault) {
                Warehouse::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return Warehouse::query()->create($data->toArray());
        });
    }

    public function update(Warehouse $warehouse, WarehouseData $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            if ($data->isDefault) {
                Warehouse::query()
                    ->where('is_default', true)
                    ->where('id', '!=', $warehouse->id)
                    ->update(['is_default' => false]);
            }

            $warehouse->update($data->toArray());

            return $warehouse->refresh();
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        $warehouse->delete();
    }
}
