<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\DTOs\WarehouseData;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    private const CACHE_LIST = 'warehouses.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Warehouse::class,
            fn (): Collection => Warehouse::query()->orderByDesc('is_default')->orderBy('name')->get()
        );
    }

    public function create(WarehouseData $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            if ($data->isDefault) {
                Warehouse::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $created = Warehouse::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created;
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

            TenantReferenceCache::forget(self::CACHE_LIST);

            return $warehouse->refresh();
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        $warehouse->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
