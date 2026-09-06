<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class SalesInvoiceQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   customer_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, SalesInvoice>
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
     *   customer_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return Builder<SalesInvoice>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = SalesInvoice::query()
            ->with([
                'customer:id,customer_code,name,status,is_system',
                'warehouse:id,name,shortcut_name,is_active',
                'currency:id,code,name',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = SalesInvoiceStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($search): void {
                $q->where('invoice_number', 'like', $search)
                    ->orWhere('reference_2', 'like', $search)
                    ->orWhereHas('customer', fn ($sq) => $sq->where('name', 'like', $search)
                        ->orWhere('customer_code', 'like', $search));
            });
        }

        if (! empty($filters['from'])) {
            $query->where('invoice_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('invoice_date', '<=', $filters['to']);
        }

        return $query;
    }
}
