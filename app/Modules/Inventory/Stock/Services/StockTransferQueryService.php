<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use Illuminate\Database\Eloquent\Collection;

class StockTransferQueryService
{
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

        if (! empty($filters['status'])) {
            $status = StockTransferStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['from_warehouse_id'])) {
            $query->where('from_warehouse_id', (int) $filters['from_warehouse_id']);
        }

        if (! empty($filters['to_warehouse_id'])) {
            $query->where('to_warehouse_id', (int) $filters['to_warehouse_id']);
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
