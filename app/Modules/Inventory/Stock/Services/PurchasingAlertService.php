<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\DTOs\PurchasingAlertResponseData;
use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Support\ReplenishmentAlertRules;
use App\Modules\Supplier\Models\SupplierItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchasingAlertService
{
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
        $query = $this->baseAlertQuery();

        if (! empty($filters['warehouse_id'])) {
            $query->where('item_warehouse_replenishments.warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_warehouse_replenishments.item_id', $filters['item_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('items.name', 'like', $term)
                    ->orWhere('items.sku', 'like', $term);
            });
        }

        /** @var Collection<int, ItemWarehouseReplenishment&object{on_hand_quantity: string|float|int}> $rows */
        $rows = $query
            ->orderBy('items.name')
            ->orderBy('item_warehouse_replenishments.warehouse_id')
            ->get();

        $preferredSuppliers = $this->preferredSuppliersByItemId(
            $rows->pluck('item_id')->unique()->all(),
        );

        $onlyAlerts = array_key_exists('only_alerts', $filters)
            ? (bool) $filters['only_alerts']
            : true;

        $statusFilter = $this->parseStatusFilter($filters['status'] ?? null);

        $results = [];

        foreach ($rows as $row) {
            $onHand = (float) $row->on_hand_quantity;
            $status = ReplenishmentAlertRules::status(
                $onHand,
                (float) $row->reorder_point_qty,
                (float) $row->safety_stock_qty,
            );

            if ($onlyAlerts && $status === ReplenishmentAlertStatus::Ok) {
                continue;
            }

            if ($statusFilter !== [] && ! in_array($status, $statusFilter, true)) {
                continue;
            }

            $results[] = PurchasingAlertResponseData::fromRow(
                $row,
                $onHand,
                $status,
                $preferredSuppliers,
            );
        }

        return $results;
    }

    public function alertCount(): int
    {
        return $this->baseAlertQuery()
            ->where(function (Builder $query): void {
                $onHand = 'COALESCE(stock_balances.quantity, 0)';

                $query->whereRaw("{$onHand} <= 0")
                    ->orWhere(function (Builder $q) use ($onHand): void {
                        $q->where('item_warehouse_replenishments.safety_stock_qty', '>', 0)
                            ->whereRaw("{$onHand} <= item_warehouse_replenishments.safety_stock_qty");
                    })
                    ->orWhere(function (Builder $q) use ($onHand): void {
                        $q->whereRaw("{$onHand} > 0")
                            ->where(function (Builder $inner) use ($onHand): void {
                                $inner->where('item_warehouse_replenishments.safety_stock_qty', '<=', 0)
                                    ->orWhereRaw("{$onHand} > item_warehouse_replenishments.safety_stock_qty");
                            })
                            ->whereRaw("{$onHand} <= item_warehouse_replenishments.reorder_point_qty");
                    });
            })
            ->count();
    }

    private function baseAlertQuery(): Builder
    {
        return ItemWarehouseReplenishment::query()
            ->select('item_warehouse_replenishments.*')
            ->join('items', 'items.id', '=', 'item_warehouse_replenishments.item_id')
            ->leftJoin('stock_balances', function ($join): void {
                $join->on('stock_balances.item_id', '=', 'item_warehouse_replenishments.item_id')
                    ->on('stock_balances.warehouse_id', '=', 'item_warehouse_replenishments.warehouse_id');
            })
            ->addSelect(DB::raw('COALESCE(stock_balances.quantity, 0) as on_hand_quantity'))
            ->where('item_warehouse_replenishments.is_active', true)
            ->where('items.track_inventory', true)
            ->where('items.allow_purchase', true)
            ->where('items.is_active', true);
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
