<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\DTOs\PurchasingAlertResponseData;
use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Support\ReplenishmentAlertRules;
use App\Modules\Supplier\Models\SupplierItem;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchasingAlertService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   warehouse_id?: int|null,
     *   item_id?: string|null,
     *   search?: string|null,
     *   status?: string|null,
     *   only_alerts?: bool
     * }  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        /** @var Collection<int, ItemWarehouseReplenishment&object{on_hand_quantity: string|float|int, on_order_quantity?: string|float|int, in_transit_in_quantity?: string|float|int}> $rows */
        $rows = $this->filteredAlertQuery($filters)
            ->orderBy('items.name')
            ->orderBy('item_warehouse_replenishments.warehouse_id')
            ->get();

        $preferredSuppliers = $this->preferredSuppliersByItemId(
            $rows->pluck('item_id')->unique()->all(),
        );

        $results = [];

        foreach ($rows as $row) {
            $results[] = $this->presentAlertRow($row, $preferredSuppliers);
        }

        return $results;
    }

    /**
     * @param  array{
     *   warehouse_id?: int|null,
     *   item_id?: string|null,
     *   search?: string|null,
     *   status?: string|null,
     *   only_alerts?: bool
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->filteredAlertQuery($filters);
        $paginator = $query
            ->orderBy('items.name')
            ->orderBy('item_warehouse_replenishments.warehouse_id')
            ->paginate($perPage);

        $preferredSuppliers = $this->preferredSuppliersByItemId(
            $paginator->getCollection()->pluck('item_id')->unique()->all(),
        );

        $paginator->getCollection()->transform(
            function (ItemWarehouseReplenishment $row) use ($preferredSuppliers): array {
                return $this->presentAlertRow($row, $preferredSuppliers);
            }
        );

        /** @var LengthAwarePaginator<int, array<string, mixed>> $paginator */
        return $paginator;
    }

    /**
     * @param  list<int>  $replenishmentIds
     * @return array<int, array<string, mixed>>
     */
    public function findByReplenishmentIds(array $replenishmentIds): array
    {
        $replenishmentIds = array_values(array_unique(array_map('intval', $replenishmentIds)));

        if ($replenishmentIds === []) {
            return [];
        }

        /** @var Collection<int, ItemWarehouseReplenishment&object{on_hand_quantity: string|float|int, on_order_quantity?: string|float|int, in_transit_in_quantity?: string|float|int}> $rows */
        $rows = $this->baseAlertQuery()
            ->whereIn('item_warehouse_replenishments.id', $replenishmentIds)
            ->get()
            ->keyBy('id');

        $preferredSuppliers = $this->preferredSuppliersByItemId(
            $rows->pluck('item_id')->unique()->all(),
        );

        $results = [];

        foreach ($replenishmentIds as $replenishmentId) {
            $row = $rows->get($replenishmentId);
            if ($row === null) {
                continue;
            }

            $results[$replenishmentId] = $this->presentAlertRow($row, $preferredSuppliers);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $replenishmentId): array
    {
        $map = $this->findByReplenishmentIds([$replenishmentId]);
        if (! isset($map[$replenishmentId])) {
            throw (new ModelNotFoundException)->setModel(ItemWarehouseReplenishment::class, [$replenishmentId]);
        }

        return $map[$replenishmentId];
    }

    public function alertCount(): int
    {
        return $this->baseAlertQuery()
            ->where(function (Builder $query): void {
                $available = self::availableSql();

                $query->whereRaw("{$available} <= 0")
                    ->orWhere(function (Builder $q) use ($available): void {
                        $q->where('item_warehouse_replenishments.safety_stock_qty', '>', 0)
                            ->whereRaw("{$available} <= item_warehouse_replenishments.safety_stock_qty");
                    })
                    ->orWhere(function (Builder $q) use ($available): void {
                        $q->whereRaw("{$available} > 0")
                            ->where(function (Builder $inner) use ($available): void {
                                $inner->where('item_warehouse_replenishments.safety_stock_qty', '<=', 0)
                                    ->orWhereRaw("{$available} > item_warehouse_replenishments.safety_stock_qty");
                            })
                            ->whereRaw("{$available} <= item_warehouse_replenishments.reorder_point_qty");
                    });
            })
            ->count();
    }

    /**
     * @param  array{
     *   warehouse_id?: int|null,
     *   item_id?: string|null,
     *   search?: string|null,
     *   status?: string|null,
     *   only_alerts?: bool
     * }  $filters
     */
    private function filteredAlertQuery(array $filters): Builder
    {
        $query = $this->baseAlertQuery();

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $this->warehouseService->assertVisibleById($warehouseId);
            $query->where('item_warehouse_replenishments.warehouse_id', $warehouseId);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_warehouse_replenishments.item_id', $filters['item_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.addcslashes(trim((string) $filters['search']), '%_\\').'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('items.name', 'like', $term)
                    ->orWhere('items.item_code', 'like', $term)
                    ->orWhere('items.sku', 'like', $term);
            });
        }

        $onlyAlerts = array_key_exists('only_alerts', $filters)
            ? (bool) $filters['only_alerts']
            : true;
        $statusFilter = $this->parseStatusFilter($filters['status'] ?? null);
        $this->constrainByComputedStatus($query, $onlyAlerts, $statusFilter);

        return $query;
    }

    /**
     * @param  list<ReplenishmentAlertStatus>  $statusFilter
     */
    private function constrainByComputedStatus(Builder $query, bool $onlyAlerts, array $statusFilter): void
    {
        $wanted = $statusFilter;
        if ($onlyAlerts) {
            $wanted = $wanted === []
                ? [
                    ReplenishmentAlertStatus::OutOfStock,
                    ReplenishmentAlertStatus::BelowSafety,
                    ReplenishmentAlertStatus::BelowReorder,
                ]
                : array_values(array_filter(
                    $wanted,
                    fn (ReplenishmentAlertStatus $status): bool => $status !== ReplenishmentAlertStatus::Ok
                ));
        }

        if ($wanted === []) {
            if ($onlyAlerts) {
                $query->whereRaw('0 = 1');
            }

            return;
        }

        $query->where(function (Builder $outer) use ($wanted): void {
            foreach ($wanted as $index => $status) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $outer->{$method}(function (Builder $q) use ($status): void {
                    $this->applySingleComputedStatus($q, $status);
                });
            }
        });
    }

    private function applySingleComputedStatus(Builder $query, ReplenishmentAlertStatus $status): void
    {
        $available = self::availableSql();

        match ($status) {
            ReplenishmentAlertStatus::OutOfStock => $query->whereRaw("{$available} <= 0"),
            ReplenishmentAlertStatus::BelowSafety => $query
                ->whereRaw("{$available} > 0")
                ->where('item_warehouse_replenishments.safety_stock_qty', '>', 0)
                ->whereRaw("{$available} <= item_warehouse_replenishments.safety_stock_qty"),
            ReplenishmentAlertStatus::BelowReorder => $query
                ->whereRaw("{$available} > 0")
                ->where(function (Builder $q) use ($available): void {
                    $q->where('item_warehouse_replenishments.safety_stock_qty', '<=', 0)
                        ->orWhereRaw("{$available} > item_warehouse_replenishments.safety_stock_qty");
                })
                ->whereRaw("{$available} <= item_warehouse_replenishments.reorder_point_qty"),
            ReplenishmentAlertStatus::Ok => $query
                ->whereRaw("{$available} > 0")
                ->where(function (Builder $q) use ($available): void {
                    $q->where('item_warehouse_replenishments.safety_stock_qty', '<=', 0)
                        ->orWhereRaw("{$available} > item_warehouse_replenishments.safety_stock_qty");
                })
                ->whereRaw("{$available} > item_warehouse_replenishments.reorder_point_qty"),
        };
    }

    private function baseAlertQuery(): Builder
    {
        $query = ItemWarehouseReplenishment::query()
            ->select('item_warehouse_replenishments.*')
            ->join('items', 'items.id', '=', 'item_warehouse_replenishments.item_id')
            ->leftJoinSub(
                StockBalance::query()
                    ->select('item_id', 'warehouse_id', DB::raw('SUM(quantity) as quantity'))
                    ->groupBy('item_id', 'warehouse_id'),
                'stock_balances',
                function ($join): void {
                    $join->on('stock_balances.item_id', '=', 'item_warehouse_replenishments.item_id')
                        ->on('stock_balances.warehouse_id', '=', 'item_warehouse_replenishments.warehouse_id');
                }
            )
            ->leftJoinSub(
                StockPipelineService::openPurchaseOrderQuery(),
                'open_pos',
                function ($join): void {
                    $join->on('open_pos.item_id', '=', 'item_warehouse_replenishments.item_id')
                        ->on('open_pos.warehouse_id', '=', 'item_warehouse_replenishments.warehouse_id');
                }
            )
            ->leftJoinSub(
                StockPipelineService::inTransitQuery('to_warehouse_id'),
                'in_transit_in',
                function ($join): void {
                    $join->on('in_transit_in.item_id', '=', 'item_warehouse_replenishments.item_id')
                        ->on('in_transit_in.to_warehouse_id', '=', 'item_warehouse_replenishments.warehouse_id');
                }
            )
            ->addSelect(DB::raw('COALESCE(stock_balances.quantity, 0) as on_hand_quantity'))
            ->addSelect(DB::raw('COALESCE(open_pos.quantity, 0) as on_order_quantity'))
            ->addSelect(DB::raw('COALESCE(in_transit_in.quantity, 0) as in_transit_in_quantity'))
            ->where('item_warehouse_replenishments.is_active', true)
            ->where('items.track_inventory', true)
            ->where('items.allow_purchase', true)
            ->where('items.is_active', true);

        $this->warehouseService->applyVisibleWarehouseConstraint(
            $query,
            'item_warehouse_replenishments.warehouse_id'
        );

        return $query;
    }

    /**
     * @param  array<string, SupplierItem>  $preferredSuppliers
     * @return array<string, mixed>
     */
    private function presentAlertRow(ItemWarehouseReplenishment $row, array $preferredSuppliers): array
    {
        $onHand = (float) $row->on_hand_quantity;
        $onOrder = (float) ($row->on_order_quantity ?? 0);
        $inTransitIn = (float) ($row->in_transit_in_quantity ?? 0);
        $projected = $onHand + $onOrder + $inTransitIn;
        $status = ReplenishmentAlertRules::status(
            $projected,
            (float) $row->reorder_point_qty,
            (float) $row->safety_stock_qty,
        );

        return PurchasingAlertResponseData::fromRow(
            $row,
            $onHand,
            $status,
            $preferredSuppliers,
            $onOrder,
            $inTransitIn,
        );
    }

    private static function availableSql(): string
    {
        return '(COALESCE(stock_balances.quantity, 0) + COALESCE(open_pos.quantity, 0) + COALESCE(in_transit_in.quantity, 0))';
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, SupplierItem>
     */
    private function preferredSuppliersByItemId(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return SupplierItem::query()
            ->with('supplier:id,supplier_code,name,is_active')
            ->whereIn('item_id', $itemIds)
            ->where('is_preferred', true)
            ->get()
            ->keyBy('item_id')
            ->all();
    }

    /**
     * @return list<ReplenishmentAlertStatus>
     */
    private function parseStatusFilter(?string $status): array
    {
        if ($status === null || trim($status) === '') {
            return [];
        }

        $values = array_filter(array_map('trim', explode(',', $status)));
        $parsed = [];

        foreach ($values as $value) {
            $enum = ReplenishmentAlertStatus::tryFrom($value);
            if ($enum !== null) {
                $parsed[] = $enum;
            }
        }

        return $parsed;
    }
}
