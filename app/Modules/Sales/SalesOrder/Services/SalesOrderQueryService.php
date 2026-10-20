<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Services;

use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class SalesOrderQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{status?:string,customer_id?:string,search?:string,from?:string,to?:string}  $filters
     * @return LengthAwarePaginator<int, SalesOrder>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SalesOrder::query()
            ->with([
                'customer:id,customer_code,name,phone,status,is_system',
                'warehouse:id,name,shortcut_name,is_active',
                'currency:id,code,name,symbol',
                'salesQuotation:id,quotation_number',
                'createdByUser:id,name,email',
            ])
            ->withCount('lines');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = SalesOrderStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('order_number', 'like', $search)
                    ->orWhereHas('customer', fn (Builder $sq) => $sq->where('name', 'like', $search)
                        ->orWhere('customer_code', 'like', $search));
            });
        }

        if (! empty($filters['from'])) {
            $query->where('order_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('order_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }
}
