<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;

class PurchaseOrderQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   supplier_id?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string,
     *   limit?:int
     * }  $filters
     * @return Collection<int, PurchaseOrder>
     */
    public function list(array $filters = []): Collection
    {
        $query = PurchaseOrder::query()
            ->with([
                'supplier:id,supplier_code,name,is_active',
                'warehouse:id,name,shortcut_name,is_active',
                'createdByUser:id,name,email',
                'confirmedByUser:id,name,email',
                'sentByUser:id,name,email',
            ])
            ->withCount('lines');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = PurchaseOrderStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (string) $filters['supplier_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $this->warehouseService->assertVisibleById($warehouseId);
            $query->where('warehouse_id', $warehouseId);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($search): void {
                $q->where('po_number', 'like', $search)
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', $search)
                        ->orWhere('supplier_code', 'like', $search));
            });
        }

        if (! empty($filters['from'])) {
            $query->where('order_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('order_date', '<=', $filters['to']);
        }

        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
