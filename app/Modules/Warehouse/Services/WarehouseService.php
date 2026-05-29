<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\DTOs\WarehouseData;
use App\Modules\Warehouse\Enums\WarehouseDefaultKind;
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
            fn (): Collection => Warehouse::query()
                ->orderByDesc('is_default')
                ->orderByDesc('is_default_sales')
                ->orderByDesc('is_default_production')
                ->orderByDesc('is_default_purchase')
                ->orderByDesc('is_default_storage')
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Active warehouse marked default for the given kind (e.g. "sales").
     * Role-specific kinds fall back to the system default ({@see WarehouseDefaultKind::General}) when unset.
     */
    public function defaultWarehouseFor(string $kind): ?Warehouse
    {
        $resolved = WarehouseDefaultKind::parse($kind);
        $warehouses = $this->list();

        $match = $warehouses->first(
            fn (Warehouse $warehouse): bool => (bool) $warehouse->{$resolved->column()}
                && (bool) $warehouse->is_active
        );

        if ($match !== null || $resolved === WarehouseDefaultKind::General) {
            return $match;
        }

        return $warehouses->first(
            fn (Warehouse $warehouse): bool => (bool) $warehouse->is_default && (bool) $warehouse->is_active
        );
    }

    public function defaultWarehouseIdFor(string $kind): ?int
    {
        return $this->defaultWarehouseFor($kind)?->id;
    }

    public function create(WarehouseData $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            $this->enforceSingleDefaults($data);

            $created = Warehouse::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created;
        });
    }

    public function update(Warehouse $warehouse, WarehouseData $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            $this->enforceSingleDefaults($data, $warehouse->id);

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

    private function enforceSingleDefaults(WarehouseData $data, ?int $exceptWarehouseId = null): void
    {
        foreach ($data->defaultFlags() as $column => $isSet) {
            if (! $isSet) {
                continue;
            }

            $query = Warehouse::query()->where($column, true);
            if ($exceptWarehouseId !== null) {
                $query->where('id', '!=', $exceptWarehouseId);
            }
            $query->update([$column => false]);
        }
    }
}
