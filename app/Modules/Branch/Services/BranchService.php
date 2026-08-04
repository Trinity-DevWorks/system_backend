<?php

declare(strict_types=1);

namespace App\Modules\Branch\Services;

use App\Modules\Branch\DTOs\BranchData;
use App\Modules\Branch\Models\Branch;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BranchService
{
    private const CACHE_LIST = 'branches.list';

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Branch::class,
            fn (): Collection => Branch::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
        );
    }

    public function create(BranchData $data): Branch
    {
        return DB::transaction(function () use ($data): Branch {
            $this->enforceSingleDefault($data);

            $created = Branch::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created;
        });
    }

    public function update(Branch $branch, BranchData $data): Branch
    {
        return DB::transaction(function () use ($branch, $data): Branch {
            $this->enforceSingleDefault($data, $branch->id);

            $branch->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $branch->refresh();
        });
    }

    public function delete(Branch $branch): void
    {
        if ($branch->is_default) {
            abort(422, 'Cannot delete the default branch. Set another branch as default first.', [
                'X-Error-Code' => 'BRANCH_DEFAULT_DELETE_FORBIDDEN',
            ]);
        }

        $branch->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    private function enforceSingleDefault(BranchData $data, ?int $exceptBranchId = null): void
    {
        if (! $data->isDefault) {
            return;
        }

        $query = Branch::query()->where('is_default', true);
        if ($exceptBranchId !== null) {
            $query->where('id', '!=', $exceptBranchId);
        }
        $query->update(['is_default' => false]);
    }
}
