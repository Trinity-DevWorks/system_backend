<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use App\Modules\Inventory\Stock\Support\StockPipelineQuantities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockPipelineService
{
    /**
     * @param  Collection<int, StockBalance>  $balances
     * @return array<string, array{on_order_qty: string, in_transit_in_qty: string, in_transit_out_qty: string, projected_qty: string}>
     */
    public function mapForBalances(Collection $balances): array
    {
        $pairs = [];
        foreach ($balances as $balance) {
            $pairs[] = [(string) $balance->item_id, (int) $balance->warehouse_id];
        }

        return $this->mapForPairs($pairs);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $pairs
     * @return array<string, array{on_order_qty: string, in_transit_in_qty: string, in_transit_out_qty: string, projected_qty: string}>
     */
    public function mapForPairs(array $pairs): array
    {
        $unique = [];
        foreach ($pairs as [$itemId, $warehouseId]) {
            if ($itemId === '' || $warehouseId <= 0) {
                continue;
            }
            $unique[StockPipelineQuantities::pairKey($itemId, $warehouseId)] = [$itemId, $warehouseId];
        }

        if ($unique === []) {
            return [];
        }

        $itemIds = array_values(array_unique(array_column($unique, 0)));
        $warehouseIds = array_values(array_unique(array_map('intval', array_column($unique, 1))));

        $onOrder = $this->sumByPair(
            self::openPurchaseOrderQuery()
                ->whereIn('purchase_order_lines.item_id', $itemIds)
                ->whereIn('purchase_orders.warehouse_id', $warehouseIds)
                ->get(),
            'warehouse_id',
        );
        $inTransitIn = $this->sumByPair(
            self::inTransitQuery('to_warehouse_id')
                ->whereIn('stock_transfer_lines.item_id', $itemIds)
                ->whereIn('stock_transfers.to_warehouse_id', $warehouseIds)
                ->get(),
            'to_warehouse_id',
        );
        $inTransitOut = $this->sumByPair(
            self::inTransitQuery('from_warehouse_id')
                ->whereIn('stock_transfer_lines.item_id', $itemIds)
                ->whereIn('stock_transfers.from_warehouse_id', $warehouseIds)
                ->get(),
            'from_warehouse_id',
        );

        $mapped = [];
        foreach ($unique as $key => [$itemId, $warehouseId]) {
            $mapped[$key] = StockPipelineQuantities::compose(
                '0',
                $onOrder[$key] ?? '0',
                $inTransitIn[$key] ?? '0',
                $inTransitOut[$key] ?? '0',
            );
        }

        return $mapped;
    }

    /**
     * Distinct item + warehouse pairs that have open PO or in-transit qty.
     */
    public static function pipelinePairsUnionQuery(): \Illuminate\Database\Query\Builder
    {
        $open = DB::query()
            ->fromSub(self::openPurchaseOrderQuery(), 'open_pos')
            ->select('item_id', 'warehouse_id')
            ->where('quantity', '>', 0);

        $inbound = DB::query()
            ->fromSub(self::inTransitQuery('to_warehouse_id'), 'in_transit_in')
            ->select('item_id', DB::raw('to_warehouse_id as warehouse_id'))
            ->where('quantity', '>', 0);

        $outbound = DB::query()
            ->fromSub(self::inTransitQuery('from_warehouse_id'), 'in_transit_out')
            ->select('item_id', DB::raw('from_warehouse_id as warehouse_id'))
            ->where('quantity', '>', 0);

        return $open->union($inbound)->union($outbound);
    }

    /**
     * Keep rows with on-hand stock or any incoming/outgoing pipeline.
     *
     * @param  Builder<StockBalance>  $query
     */
    public static function constrainHasOnHandOrPipeline(Builder $query): void
    {
        $query->where(function (Builder $outer): void {
            $outer->where('stock_balances.quantity', '>', 0)
                ->orWhereExists(function ($exists): void {
                    $exists->selectRaw('1')
                        ->from('purchase_order_lines')
                        ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
                        ->whereColumn('purchase_order_lines.item_id', 'stock_balances.item_id')
                        ->whereColumn('purchase_orders.warehouse_id', 'stock_balances.warehouse_id')
                        ->whereIn('purchase_orders.status', [
                            PurchaseOrderStatus::Confirmed->value,
                            PurchaseOrderStatus::Sent->value,
                        ])
                        ->whereColumn(
                            'purchase_order_lines.base_quantity',
                            '>',
                            'purchase_order_lines.received_base_quantity'
                        );
                })
                ->orWhereExists(function ($exists): void {
                    $exists->selectRaw('1')
                        ->from('stock_transfer_lines')
                        ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
                        ->whereColumn('stock_transfer_lines.item_id', 'stock_balances.item_id')
                        ->whereColumn('stock_transfers.to_warehouse_id', 'stock_balances.warehouse_id')
                        ->where('stock_transfers.status', StockTransferStatus::InTransit->value);
                })
                ->orWhereExists(function ($exists): void {
                    $exists->selectRaw('1')
                        ->from('stock_transfer_lines')
                        ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
                        ->whereColumn('stock_transfer_lines.item_id', 'stock_balances.item_id')
                        ->whereColumn('stock_transfers.from_warehouse_id', 'stock_balances.warehouse_id')
                        ->where('stock_transfers.status', StockTransferStatus::InTransit->value);
                });
        });
    }

    /**
     * Remaining confirmed/sent PO qty in base UOM, by item + receiving warehouse.
     *
     * @return Builder<PurchaseOrderLine>
     */
    public static function openPurchaseOrderQuery(): Builder
    {
        return PurchaseOrderLine::query()
            ->select([
                'purchase_order_lines.item_id',
                'purchase_orders.warehouse_id',
                DB::raw(
                    'SUM(CASE WHEN purchase_order_lines.base_quantity > purchase_order_lines.received_base_quantity'
                    .' THEN purchase_order_lines.base_quantity - purchase_order_lines.received_base_quantity ELSE 0 END) as quantity'
                ),
            ])
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereIn('purchase_orders.status', [
                PurchaseOrderStatus::Confirmed->value,
                PurchaseOrderStatus::Sent->value,
            ])
            ->groupBy('purchase_order_lines.item_id', 'purchase_orders.warehouse_id');
    }

    /**
     * In-transit transfer qty in base UOM, grouped by item + from or to warehouse.
     *
     * @param  'from_warehouse_id'|'to_warehouse_id'  $warehouseColumn
     * @return Builder<StockTransferLine>
     */
    public static function inTransitQuery(string $warehouseColumn): Builder
    {
        if (! in_array($warehouseColumn, ['from_warehouse_id', 'to_warehouse_id'], true)) {
            throw new \InvalidArgumentException('Invalid transfer warehouse column.');
        }

        return StockTransferLine::query()
            ->select([
                'stock_transfer_lines.item_id',
                "stock_transfers.{$warehouseColumn}",
                DB::raw('SUM(stock_transfer_lines.base_quantity) as quantity'),
            ])
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
            ->where('stock_transfers.status', StockTransferStatus::InTransit->value)
            ->groupBy('stock_transfer_lines.item_id', "stock_transfers.{$warehouseColumn}");
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, string>
     */
    private function sumByPair(Collection $rows, string $warehouseKey): array
    {
        $sums = [];
        foreach ($rows as $row) {
            $itemId = (string) $row->item_id;
            $warehouseId = (int) $row->{$warehouseKey};
            $key = StockPipelineQuantities::pairKey($itemId, $warehouseId);
            $sums[$key] = StockPipelineQuantities::qty($row->quantity ?? 0);
        }

        return $sums;
    }
}
