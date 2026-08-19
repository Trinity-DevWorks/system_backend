<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class StockBalanceService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{warehouse_id?:int,item_id?:int|string,search?:string,only_tracked?:bool,only_with_stock?:bool}  $filters
     * @return LengthAwarePaginator<int, StockBalance>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->orderBy('warehouse_id')
            ->orderBy('item_id')
            ->paginate($perPage);
    }

    /**
     * @param  array{warehouse_id?:int,item_id?:int|string,search?:string,only_tracked?:bool,only_with_stock?:bool}  $filters
     * @return Builder<StockBalance>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = StockBalance::query()
            ->with([
                'item:id,sku,item_code,name,base_uom_id,track_inventory,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ]);

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $this->warehouseService->assertVisibleById($warehouseId);
            $query->where('warehouse_id', $warehouseId);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        if (! empty($filters['only_tracked'])) {
            $query->whereHas('item', fn (Builder $q) => $q->where('track_inventory', true));
        }

        if (! empty($filters['only_with_stock'])) {
            $query->where('quantity', '>', 0);
        }

        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(trim((string) $filters['search']), '%_\\').'%';
            $query->whereHas('item', function (Builder $q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('item_code', 'like', $term)
                    ->orWhere('sku', 'like', $term);
            });
        }

        return $query;
    }

    public function findForItemWarehouse(string $itemId, int $warehouseId): ?StockBalance
    {
        $this->warehouseService->assertVisibleById($warehouseId);

        return StockBalance::query()
            ->with([
                'item:id,sku,item_code,name,base_uom_id,track_inventory,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ])
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }
}
