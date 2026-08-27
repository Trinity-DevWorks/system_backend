<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\BundleExplosionStatus;
use App\Modules\Inventory\Stock\Models\BundleExplosion;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class BundleExplosionQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   item_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, BundleExplosion>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   item_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return Builder<BundleExplosion>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = BundleExplosion::query()
            ->with([
                'warehouse:id,name,shortcut_name,is_active',
                'item:id,item_code,name,is_active,track_inventory,track_lots',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = BundleExplosionStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $this->warehouseService->assertVisibleById($warehouseId);
            $query->where('warehouse_id', $warehouseId);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', (string) $filters['item_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($search): void {
                $q->where('bex_number', 'like', $search)
                    ->orWhere('notes', 'like', $search);
            });
        }

        if (! empty($filters['from'])) {
            $query->where('explosion_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('explosion_date', '<=', $filters['to']);
        }

        return $query;
    }
}
