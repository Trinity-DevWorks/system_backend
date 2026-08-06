<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Services;

use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Warehouse\DTOs\WarehouseData;
use App\Modules\Warehouse\Enums\WarehouseDefaultKind;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    private const CACHE_LIST = 'warehouses.list';

    public function __construct(
        private readonly BranchContextService $branchContext,
    ) {}

    public function list(): Collection
    {
        $warehouses = TenantReferenceCache::rememberModels(
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

        // Cache stores attributes only; re-eager-load relations after hydrate.
        $warehouses->load(['branch:id,name', 'manager:id,name']);

        return $this->filterForActiveBranch($warehouses);
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

    public function assertVisible(Warehouse $warehouse): void
    {
        if (! $this->isVisibleInActiveBranch($warehouse)) {
            abort(403, 'This warehouse is not available in the active branch.', [
                'X-Error-Code' => 'WAREHOUSE_BRANCH_FORBIDDEN',
            ]);
        }
    }

    public function create(WarehouseData $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            $this->assertWritableBranch($data->branchId);
            $this->enforceSingleDefaults($data);

            $created = Warehouse::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created->load(['branch:id,name', 'manager:id,name']);
        });
    }

    public function update(Warehouse $warehouse, WarehouseData $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            $this->assertVisible($warehouse);
            $this->assertWritableBranch($data->branchId);
            $this->enforceSingleDefaults($data, $warehouse->id);

            $warehouse->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $warehouse->refresh()->load(['branch:id,name', 'manager:id,name']);
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        $this->assertVisible($warehouse);
        $warehouse->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    /**
     * @param  Collection<int, Warehouse>  $warehouses
     * @return Collection<int, Warehouse>
     */
    private function filterForActiveBranch(Collection $warehouses): Collection
    {
        $activeBranchId = $this->branchContext->resolveActiveBranchId();
        if ($activeBranchId === null) {
            return $warehouses;
        }

        return $warehouses
            ->filter(fn (Warehouse $warehouse): bool => $this->isVisibleInActiveBranch($warehouse, $activeBranchId))
            ->values();
    }

    private function isVisibleInActiveBranch(Warehouse $warehouse, ?int $activeBranchId = null): bool
    {
        $activeBranchId ??= $this->branchContext->resolveActiveBranchId();
        if ($activeBranchId === null) {
            return true;
        }

        // Shared central/distribution warehouses (no branch) are visible in every branch context.
        if ($warehouse->branch_id === null) {
            return true;
        }

        return (int) $warehouse->branch_id === $activeBranchId;
    }

    private function assertWritableBranch(?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $activeBranchId = $this->branchContext->resolveActiveBranchId();
        if ($activeBranchId !== null && $branchId !== $activeBranchId) {
            abort(422, 'Warehouse branch must match the active branch.', [
                'X-Error-Code' => 'WAREHOUSE_BRANCH_MISMATCH',
            ]);
        }

        if (! $this->branchContext->canAccessBranch($branchId)) {
            abort(403, 'You cannot assign this warehouse to a branch you cannot access.', [
                'X-Error-Code' => 'BRANCH_ACCESS_DENIED',
            ]);
        }
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
