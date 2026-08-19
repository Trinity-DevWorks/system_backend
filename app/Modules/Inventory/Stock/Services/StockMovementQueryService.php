<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class StockMovementQueryService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    public function find(int $id): StockMovement
    {
        $query = StockMovement::query()->whereKey($id);
        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        return $query->firstOrFail();
    }

    /**
     * @param  array{
     *   warehouse_id?:int,
     *   item_id?:int|string,
     *   type?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{
     *   warehouse_id?:int,
     *   item_id?:int|string,
     *   type?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return Builder<StockMovement>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = StockMovement::query()
            ->with([
                'item:id,sku,item_code,name,base_uom_id',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name',
                'itemUom.uom:id,code,name',
                'user:id,name,email',
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

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            ListPagination::applySearch(
                $query,
                $search,
                ['notes'],
                [
                    'item' => ['sku', 'item_code', 'name'],
                    'warehouse' => ['name', 'shortcut_name'],
                    'user' => ['name', 'email'],
                ],
            );
        }

        return $query;
    }
}
