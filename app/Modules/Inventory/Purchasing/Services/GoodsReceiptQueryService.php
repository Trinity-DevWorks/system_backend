<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class GoodsReceiptQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   purchase_order_id?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, GoodsReceipt>
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
     *   purchase_order_id?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return Builder<GoodsReceipt>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = GoodsReceipt::query()
            ->with([
                'purchaseOrder:id,po_number,supplier_id,status',
                'purchaseOrder.supplier:id,supplier_code,name,is_active',
                'supplier:id,supplier_code,name,is_active',
                'warehouse:id,name,shortcut_name,is_active',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = GoodsReceiptStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', $filters['purchase_order_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $this->warehouseService->assertVisibleById($warehouseId);
            $query->where('warehouse_id', $warehouseId);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($search): void {
                $q->where('grn_number', 'like', $search)
                    ->orWhere('notes', 'like', $search)
                    ->orWhereHas('purchaseOrder', function ($po) use ($search): void {
                        $po->where('po_number', 'like', $search);
                    });
            });
        }

        if (! empty($filters['from'])) {
            $query->where('received_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('received_date', '<=', $filters['to']);
        }

        return $query;
    }
}
