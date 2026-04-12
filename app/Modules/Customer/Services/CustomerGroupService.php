<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\DTOs\CustomerGroupData;
use App\Modules\Customer\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CustomerGroupService
{
    public function list(): Collection
    {
        return CustomerGroup::query()->orderBy('name')->get();
    }

    public function names(): Collection
    {
        return CustomerGroup::query()
            ->select(['id', 'name', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    public function create(CustomerGroupData $data): CustomerGroup
    {
        return DB::transaction(function () use ($data): CustomerGroup {
            return CustomerGroup::query()->create($data->toArray());
        });
    }

    public function update(CustomerGroup $group, CustomerGroupData $data): CustomerGroup
    {
        return DB::transaction(function () use ($group, $data): CustomerGroup {
            $group->update($data->toArray());

            return $group->refresh();
        });
    }

    public function delete(CustomerGroup $group): void
    {
        $group->delete();
    }
}
