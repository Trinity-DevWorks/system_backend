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
     *   status?:string|null,
     *   purchase_order_id?:string|null,
     *   supplier_id?:string|null,
     *   warehouse_id?:int|null,
     *   available_for_invoice?:bool|null,
     *   except_purchase_invoice_id?:string|null,
     *   search?:string|null,
     *   from?:string|null,
     *   to?:string|null
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
     *   status?:string|null,
     *   purchase_order_id?:string|null,
     *   supplier_id?:string|null,
     *   warehouse_id?:int|null,
     *   available_for_invoice?:bool|null,
     *   except_purchase_invoice_id?:string|null,
     *   search?:string|null,
     *   from?:string|null,
     *   to?:string|null
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

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['available_for_invoice'])) {
            $exceptId = $filters['except_purchase_invoice_id'] ?? null;
            $query->whereNotExists(function ($sub) use ($exceptId): void {
                $sub->selectRaw('1')
                    ->from('purchase_invoices')
                    ->whereColumn('purchase_invoices.goods_receipt_id', 'goods_receipts.id');
                if (is_string($exceptId) && $exceptId !== '') {
                    $sub->where('purchase_invoices.id', '!=', $exceptId);
                }
            });
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
