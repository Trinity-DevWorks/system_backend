<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;

class StockMovementQueryService
{
    /**
     * @param  array{
     *   warehouse_id?:int,
     *   item_id?:int,
     *   type?:string,
     *   from?:string,
     *   to?:string,
     *   limit?:int
     * }  $filters
     * @return Collection<int, StockMovement>
     */
    public function list(array $filters = []): Collection
    {
        $query = StockMovement::query()
            ->with([
                'item:id,sku,name,base_uom_id',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name',
                'itemUom.uom:id,code,name',
                'user:id,name,email',
            ]);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        if (! empty($filters['type'])) {
            $type = StockMovementType::tryFrom((string) $filters['type']);
            if ($type) {
                $query->where('type', $type->value);
            }
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
