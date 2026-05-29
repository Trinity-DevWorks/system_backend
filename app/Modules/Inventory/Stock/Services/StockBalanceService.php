<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Models\StockBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StockBalanceService
{
    /**
     * @param  array{warehouse_id?:int,item_id?:int,search?:string,only_tracked?:bool,only_with_stock?:bool}  $filters
     * @return Collection<int, StockBalance>
     */
    public function list(array $filters = []): Collection
    {
        $query = StockBalance::query()
            ->with([
                'item:id,sku,name,base_uom_id,track_inventory,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ]);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', (int) $filters['item_id']);
        }

        if (! empty($filters['only_tracked'])) {
            $query->whereHas('item', fn (Builder $q) => $q->where('track_inventory', true));
        }

        if (! empty($filters['only_with_stock'])) {
            $query->where('quantity', '>', 0);
        }

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->whereHas('item', function (Builder $q) use ($term): void {
                $q->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            });
        }

        return $query
            ->orderBy('warehouse_id')
            ->orderBy('item_id')
            ->get();
    }

    public function findForItemWarehouse(int $itemId, int $warehouseId): ?StockBalance
    {
        return StockBalance::query()
            ->with([
                'item:id,sku,name,base_uom_id,track_inventory,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ])
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }
}
