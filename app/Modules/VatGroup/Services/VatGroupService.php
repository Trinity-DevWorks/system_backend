<?php

namespace App\Modules\VatGroup\Services;

use App\Modules\VatGroup\DTOs\VatGroupData;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VatGroupService
{
    public function list(): Collection
    {
        return VatGroup::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function names(): Collection
    {
        return VatGroup::query()
            ->select(['id', 'abrv', 'name', 'percentage', 'is_default', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    public function create(VatGroupData $data): VatGroup
    {
        return DB::transaction(function () use ($data): VatGroup {
            if ($data->isDefault) {
                VatGroup::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return VatGroup::query()->create($data->toArray());
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

            return $vatGroup->refresh();
        });
    }

    public function delete(VatGroup $vatGroup): void
    {
        $vatGroup->delete();
    }
}
