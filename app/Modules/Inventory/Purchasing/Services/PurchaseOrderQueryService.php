<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

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
     *   available_for_invoice?:bool|null,
     *   available_for_receipt?:bool|null,
     *   except_purchase_invoice_id?:string|null
     * }  $filters
     * @return LengthAwarePaginator<int, PurchaseOrder>
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
     *   supplier_id?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string,
     *   available_for_invoice?:bool|null,
     *   available_for_receipt?:bool|null,
     *   except_purchase_invoice_id?:string|null
     * }  $filters
     * @return Builder<PurchaseOrder>
     */
    private function filteredQuery(array $filters = []): Builder
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

        if (! empty($filters['available_for_invoice'])) {
            $exceptId = $filters['except_purchase_invoice_id'] ?? null;
            $query->whereIn('status', [
                PurchaseOrderStatus::Confirmed->value,
                PurchaseOrderStatus::Sent->value,
            ]);
            $query->whereNotExists(function ($sub) use ($exceptId): void {
                $sub->selectRaw('1')
                    ->from('purchase_invoices')
                    ->whereColumn('purchase_invoices.purchase_order_id', 'purchase_orders.id');
                if (is_string($exceptId) && $exceptId !== '') {
                    $sub->where('purchase_invoices.id', '!=', $exceptId);
                }
            });
            $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('goods_receipts')
                    ->whereColumn('goods_receipts.purchase_order_id', 'purchase_orders.id');
            });
        }

        if (! empty($filters['available_for_receipt'])) {
            $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('purchase_invoices')
                    ->whereColumn('purchase_invoices.purchase_order_id', 'purchase_orders.id');
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

        return $query;
    }
}
