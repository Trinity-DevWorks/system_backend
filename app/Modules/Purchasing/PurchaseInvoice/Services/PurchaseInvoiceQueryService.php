<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Services;

use App\Modules\Purchasing\PurchaseInvoice\Enums\PurchaseInvoiceStatus;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseInvoiceQueryService
{
    /**
     * @param  array{
     *   status?:string,
     *   supplier_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, PurchaseInvoice>
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
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return Builder<PurchaseInvoice>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = PurchaseInvoice::query()
            ->with([
                'supplier:id,supplier_code,name,is_active',
                'currency:id,code,name,symbol,iso_code,is_active',
                'paymentTerm:id,code,name,due_days',
                'paymentMethod:id,code,name',
                'purchaseOrder:id,po_number,status',
                'goodsReceipt:id,grn_number,status',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        if (! empty($filters['status'])) {
            $status = PurchaseInvoiceStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($search): void {
                $q->where('invoice_number', 'like', $search)
                    ->orWhere('supplier_reference', 'like', $search)
                    ->orWhere('notes', 'like', $search);
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
