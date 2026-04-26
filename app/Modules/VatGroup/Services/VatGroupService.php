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
        $vatGroup->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
