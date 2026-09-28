<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PurchaseInvoiceQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{status?:string|null,supplier_id?:string|null,search?:string|null,from?:string|null,to?:string|null}  $filters
     * @return LengthAwarePaginator<int, PurchaseInvoice>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?:string|null,supplier_id?:string|null,search?:string|null,from?:string|null,to?:string|null}  $filters
     * @return Builder<PurchaseInvoice>
     */
    private function filtered(array $filters): Builder
    {
        $query = PurchaseInvoice::query()
            ->with([
                'supplier:id,supplier_code,name,is_active',
                'warehouse:id,name,shortcut_name',
                'currency:id,code,name',
                'goodsReceipt:id,grn_number',
            ]);

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = PurchaseInvoiceStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('invoice_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('invoice_date', '<=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('invoice_number', 'like', $like)
                    ->orWhere('reference_2', 'like', $like)
                    ->orWhereHas('supplier', function (Builder $supplier) use ($like): void {
                        $supplier->where('name', 'like', $like)
                            ->orWhere('supplier_code', 'like', $like);
                    });
            });
        }

        return $query;
    }
}
