<?php

declare(strict_types=1);

namespace App\Modules\Branch\Services;

use App\Models\User;
use App\Modules\Branch\DTOs\BranchData;
use App\Modules\Branch\Models\Branch;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public const DEFAULT_NAME = 'Main';

    public const DEFAULT_SHORTCUT = 'MAIN';

    private const CACHE_LIST = 'branches.list';

    public function list(): Collection
    {
        $branches = TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Branch::class,
            fn (): Collection => Branch::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
        );

        // Cache stores attributes only; re-eager-load relations after hydrate.
        $branches->load('manager:id,name');

        return $branches;
    }

    /**
     * Ensure the tenant has exactly one usable default branch (idempotent).
     * Creates Main / MAIN when the table is empty; promotes the oldest row if none is marked default.
     */
    public function ensureDefaultBranch(): Branch
    {
        return DB::transaction(function (): Branch {
            $default = Branch::query()->where('is_default', true)->orderBy('id')->first();
            if ($default !== null) {
                return $default->loadMissing('manager:id,name');
            }

            $existing = Branch::query()->orderBy('id')->first();
            if ($existing !== null) {
                Branch::query()->whereKey($existing->id)->update(['is_default' => true]);
                TenantReferenceCache::forget(self::CACHE_LIST);

                return $existing->refresh()->loadMissing('manager:id,name');
            }

            $created = Branch::query()->create([
                'name' => self::DEFAULT_NAME,
                'shortcut_name' => self::DEFAULT_SHORTCUT,
                'is_active' => true,
                'is_default' => true,
            ]);
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created;
        });
    }

    public function defaultBranch(): Branch
    {
        return $this->ensureDefaultBranch();
    }

    public function defaultBranchId(): int
    {
        return $this->ensureDefaultBranch()->id;
    }

    public function assignUserToDefaultBranch(User $user, int $roleId): void
    {
        $defaultId = $this->defaultBranchId();
        $user->branches()->syncWithoutDetaching([
            $defaultId => ['role_id' => $roleId],
        ]);
    }

    public function create(BranchData $data): Branch
    {
        return DB::transaction(function () use ($data): Branch {
            $payload = $data->toArray();

            // First branch in a tenant must be the default.
            if (! Branch::query()->exists()) {
                $payload['is_default'] = true;
            }

            if ($payload['is_default']) {
                Branch::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $created = Branch::query()->create($payload);
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created->load('manager:id,name');
        });
    }

    public function update(Branch $branch, BranchData $data): Branch
    {
        return DB::transaction(function () use ($branch, $data): Branch {
            if ($branch->is_default && ! $data->isDefault) {
                abort(422, 'Cannot unset the default branch. Set another branch as default first.', [
                    'X-Error-Code' => 'BRANCH_DEFAULT_UNSET_FORBIDDEN',
                ]);
            }

            $this->enforceSingleDefault($data, $branch->id);

            $branch->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $branch->refresh()->load('manager:id,name');
        });
    }

    public function delete(Branch $branch): void
    {
        if ($branch->is_default) {
            abort(422, 'Cannot delete the default branch. Set another branch as default first.', [
                'X-Error-Code' => 'BRANCH_DEFAULT_DELETE_FORBIDDEN',
            ]);
        }

        if ($branch->warehouses()->exists()) {
            abort(409, 'Cannot delete a branch that still has warehouses. Reassign or delete them first.', [
                'X-Error-Code' => 'BRANCH_DELETE_HAS_WAREHOUSES',
            ]);
        }

        if ($branch->users()->exists()) {
            abort(409, 'Cannot delete a branch that still has users assigned. Reassign them first.', [
                'X-Error-Code' => 'BRANCH_DELETE_HAS_USERS',
            ]);
        }

        if ($branch->salesmen()->exists()) {
            abort(409, 'Cannot delete a branch that still has salesmen. Reassign them first.', [
                'X-Error-Code' => 'BRANCH_DELETE_HAS_SALESMEN',
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
