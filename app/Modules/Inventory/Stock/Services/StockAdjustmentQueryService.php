<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class StockAdjustmentQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   reason_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, StockAdjustment>
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
     *   reason_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return Builder<StockAdjustment>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = StockAdjustment::query()
            ->with([
                'warehouse:id,name,shortcut_name,is_active',
                'reason:id,code,name,direction,is_active',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = StockAdjustmentStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $this->warehouseService->assertVisibleById($warehouseId);
            $query->where('warehouse_id', $warehouseId);
        }

        if (! empty($filters['reason_id'])) {
            $query->where('stock_adjustment_reason_id', (int) $filters['reason_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($search): void {
                $q->where('adj_number', 'like', $search)
                    ->orWhere('notes', 'like', $search);
            });
        }

        if (! empty($filters['from'])) {
            $query->where('adjustment_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('adjustment_date', '<=', $filters['to']);
        }

        return $query;
    }
}
