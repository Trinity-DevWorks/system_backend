<?php

namespace App\Modules\VatGroup\Services;

use App\Modules\VatGroup\DTOs\VatGroupData;
use App\Modules\VatGroup\Models\VatGroup;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VatGroupService
{
    private const CACHE_LIST = 'vat_groups.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            VatGroup::class,
            fn (): Collection => VatGroup::query()->orderByDesc('is_default')->orderBy('name')->get()
        );
    }

    public function create(VatGroupData $data): VatGroup
    {
        return DB::transaction(function () use ($data): VatGroup {
            if ($data->isDefault) {
                VatGroup::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $created = VatGroup::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created;
        });
    }

    public function update(VatGroup $vatGroup, VatGroupData $data): VatGroup
    {
        return DB::transaction(function () use ($vatGroup, $data): VatGroup {
            if ($data->isDefault) {
                VatGroup::query()
                    ->where('is_default', true)
                    ->where('id', '!=', $vatGroup->id)
                    ->update(['is_default' => false]);
            }

            $vatGroup->update($data->toArray());

            TenantReferenceCache::forget(self::CACHE_LIST);

            return $vatGroup->refresh();
        });
    }

    public function delete(VatGroup $vatGroup): void
    {
        if ($vatGroup->is_default) {
            abort(422, 'Cannot delete the default VAT group. Set another VAT group as default first.', [
                'X-Error-Code' => 'VAT_GROUP_DEFAULT_DELETE_FORBIDDEN',
            ]);
        }

        if ($vatGroup->items()->withTrashed()->exists()) {
            abort(409, 'Cannot delete a VAT group that is assigned to items (including soft-deleted items).', [
                'X-Error-Code' => 'VAT_GROUP_DELETE_REFERENCED_BY_ITEMS',
            ]);
        }

        if ($vatGroup->customers()->withTrashed()->exists()) {
            abort(409, 'Cannot delete a VAT group that is assigned to customers (including soft-deleted customers).', [
                'X-Error-Code' => 'VAT_GROUP_DELETE_REFERENCED_BY_CUSTOMERS',
            ]);
        }

        if ($vatGroup->suppliers()->withTrashed()->exists()) {
            abort(409, 'Cannot delete a VAT group that is assigned to suppliers (including soft-deleted suppliers).', [
                'X-Error-Code' => 'VAT_GROUP_DELETE_REFERENCED_BY_SUPPLIERS',
            ]);
        }

        $vatGroup->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
