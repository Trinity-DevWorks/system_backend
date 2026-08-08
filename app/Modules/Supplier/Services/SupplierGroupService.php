<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\Supplier\DTOs\SupplierGroupData;
use App\Modules\Supplier\Models\SupplierGroup;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SupplierGroupService
{
    private const CACHE_LIST = 'supplier_groups.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            SupplierGroup::class,
            fn (): Collection => SupplierGroup::query()->orderBy('name')->get()
        );
    }

    public function create(SupplierGroupData $data): SupplierGroup
    {
        return DB::transaction(function () use ($data): SupplierGroup {
            $model = SupplierGroup::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $model;
        });
    }

    public function update(SupplierGroup $group, SupplierGroupData $data): SupplierGroup
    {
        return DB::transaction(function () use ($group, $data): SupplierGroup {
            $group->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $group->refresh();
        });
    }

    public function delete(SupplierGroup $group): void
    {
        if ($group->suppliers()->withTrashed()->exists()) {
            abort(409, 'Cannot delete a supplier group while members exist (including soft-deleted members). Reassign or remove them first.', [
                'X-Error-Code' => 'SUPPLIER_GROUP_DELETE_HAS_MEMBERS',
            ]);
        }

        $group->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
