<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;

class StockTransferQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   from_warehouse_id?:int,
     *   to_warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string,
     *   limit?:int
     * }  $filters
     * @return Collection<int, StockTransfer>
     */
    public function list(array $filters = []): Collection
    {
        $query = StockTransfer::query()
            ->with([
                'fromWarehouse:id,name,shortcut_name,is_active',
                'toWarehouse:id,name,shortcut_name,is_active',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        $visibleIds = $this->warehouseService->visibleWarehouseIds();
        if ($visibleIds !== null) {
            if ($visibleIds === []) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereIn('from_warehouse_id', $visibleIds)
                    ->whereIn('to_warehouse_id', $visibleIds);
            }
        }

        if (! empty($filters['status'])) {
            $status = StockTransferStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['from_warehouse_id'])) {
            $fromId = (int) $filters['from_warehouse_id'];
            $this->warehouseService->assertVisibleById($fromId);
            $query->where('from_warehouse_id', $fromId);
        }

        if (! empty($filters['to_warehouse_id'])) {
            $toId = (int) $filters['to_warehouse_id'];
            $this->warehouseService->assertVisibleById($toId);
            $query->where('to_warehouse_id', $toId);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where('transfer_number', 'like', $search);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
