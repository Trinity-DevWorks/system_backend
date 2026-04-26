<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\DTOs\CustomerGroupData;
use App\Modules\Customer\Models\CustomerGroup;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CustomerGroupService
{
    private const CACHE_LIST = 'customer_groups.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            CustomerGroup::class,
            fn (): Collection => CustomerGroup::query()->orderBy('name')->get()
        );
    }

    public function create(CustomerGroupData $data): CustomerGroup
    {
        return DB::transaction(function () use ($data): CustomerGroup {
            $model = CustomerGroup::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $model;
        });
    }

    public function update(CustomerGroup $group, CustomerGroupData $data): CustomerGroup
    {
        return DB::transaction(function () use ($group, $data): CustomerGroup {
            $group->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $group->refresh();
        });
    }

    public function delete(CustomerGroup $group): void
    {
        $group->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
