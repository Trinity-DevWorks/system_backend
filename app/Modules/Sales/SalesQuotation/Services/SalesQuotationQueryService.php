<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Services;

use App\Modules\Sales\SalesQuotation\Enums\SalesQuotationStatus;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class SalesQuotationQueryService
{
    /**
     * @param  array{status?:string,customer_id?:string,search?:string,from?:string,to?:string}  $filters
     * @return LengthAwarePaginator<int, SalesQuotation>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SalesQuotation::query()
            ->with([
                'customer:id,customer_code,name,phone,status,is_system',
                'currency:id,code,name,symbol',
                'salesOrder:id,order_number',
                'createdByUser:id,name,email',
            ])
            ->withCount('lines');

        if (! empty($filters['status'])) {
            $status = SalesQuotationStatus::tryFrom((string) $filters['status']);
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
                $q->where('quotation_number', 'like', $search)
                    ->orWhereHas('customer', fn (Builder $sq) => $sq->where('name', 'like', $search)
                        ->orWhere('customer_code', 'like', $search));
            });
        }

        if (! empty($filters['from'])) {
            $query->where('quotation_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('quotation_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }
}
