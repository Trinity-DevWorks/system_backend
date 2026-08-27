<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

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
        $realQuery = $this->filteredQuery($filters)
            ->orderBy('warehouse_id')
            ->orderBy('item_id')
            ->orderBy('lot_id');

        $pipelineOnlyPairs = $this->pipelineOnlyPairs($filters);
        $page = Paginator::resolveCurrentPage();
        $realTotal = (clone $realQuery)->toBase()->getCountForPagination();
        $total = $realTotal + count($pipelineOnlyPairs);
        $offset = max(0, ($page - 1) * $perPage);

        if ($offset >= $realTotal) {
            $realRows = new Collection;
            $syntheticPairs = array_slice($pipelineOnlyPairs, $offset - $realTotal, $perPage);
        } else {
            $realRows = $realQuery->offset($offset)->limit($perPage)->get();
            $need = $perPage - $realRows->count();
            $syntheticPairs = $need > 0 ? array_slice($pipelineOnlyPairs, 0, $need) : [];
        }

        $rows = $realRows->concat($this->hydratePipelineOnly($syntheticPairs))->values();

        return new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * @param  array{warehouse_id?:int,item_id?:int|string,search?:string,only_tracked?:bool,only_with_stock?:bool}  $filters
     * @return Builder<StockBalance>
     */
    private function filteredQuery(array $filters = []): Builder
    {
        $query = StockBalance::query()
            ->with([
                'item:id,item_code,name,base_uom_id,track_inventory,track_lots,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
                'lot:id,lot_number,expiry_date',
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
            StockPipelineService::constrainHasOnHandOrPipeline($query);
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

    public function findForItemWarehouse(string $itemId, int $warehouseId, ?int $lotId = null): ?StockBalance
    {
        $this->warehouseService->assertVisibleById($warehouseId);

        $query = StockBalance::query()
            ->with([
                'item:id,item_code,name,base_uom_id,track_inventory,track_lots,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
                'lot:id,lot_number,expiry_date',
            ])
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId);

        if ($lotId === null) {
            $query->whereNull('lot_id');
        } else {
            $query->where('lot_id', $lotId);
        }

        return $query->first();
    }

    /**
     * Item + warehouse pairs with open PO / in-transit and no balance row yet.
     *
     * @param  array{warehouse_id?:int,item_id?:int|string,search?:string,only_tracked?:bool}  $filters
     * @return list<array{0: string, 1: int}>
     */
    private function pipelineOnlyPairs(array $filters): array
    {
        $query = DB::query()
            ->fromSub(StockPipelineService::pipelinePairsUnionQuery(), 'pipeline_pairs')
            ->select('pipeline_pairs.item_id', 'pipeline_pairs.warehouse_id')
            ->join('items', 'items.id', '=', 'pipeline_pairs.item_id')
            ->whereNotExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('stock_balances')
                    ->whereColumn('stock_balances.item_id', 'pipeline_pairs.item_id')
                    ->whereColumn('stock_balances.warehouse_id', 'pipeline_pairs.warehouse_id');
            });

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'pipeline_pairs.warehouse_id');

        if (! empty($filters['warehouse_id'])) {
            $query->where('pipeline_pairs.warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['item_id'])) {
            $query->where('pipeline_pairs.item_id', $filters['item_id']);
        }

        if (! empty($filters['only_tracked'])) {
            $query->where('items.track_inventory', true);
        }

        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(trim((string) $filters['search']), '%_\\').'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('items.name', 'like', $term)
                    ->orWhere('items.item_code', 'like', $term)
                    ->orWhere('items.sku', 'like', $term);
            });
        }

        return $query
            ->orderBy('pipeline_pairs.warehouse_id')
            ->orderBy('pipeline_pairs.item_id')
            ->get()
            ->map(fn (object $row): array => [(string) $row->item_id, (int) $row->warehouse_id])
            ->all();
    }

    /**
     * @param  list<array{0: string, 1: int}>  $pairs
     * @return Collection<int, StockBalance>
     */
    private function hydratePipelineOnly(array $pairs): Collection
    {
        $models = new Collection;
        foreach ($pairs as [$itemId, $warehouseId]) {
            $models->push(new StockBalance([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'lot_id' => null,
                'quantity' => 0,
                'unit_cost' => 0,
                'inventory_value' => 0,
            ]));
        }

        if ($models->isNotEmpty()) {
            $models->load([
                'item:id,item_code,name,base_uom_id,track_inventory,track_lots,is_active',
                'item.baseUom:id,code,name',
                'warehouse:id,name,shortcut_name,is_active',
            ]);
        }

        return $models;
    }
}
