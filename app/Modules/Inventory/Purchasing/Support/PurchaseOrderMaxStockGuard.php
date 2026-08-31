<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockPipelineService;
use App\Modules\Inventory\Stock\Support\StockPipelineQuantities;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderMaxStockGuard
{
    public function __construct(
        private readonly StockPipelineService $pipeline,
    ) {}

    public function assertOrder(PurchaseOrder $order): void
    {
        $qtyByItem = [];

        $lines = PurchaseOrderLine::query()
            ->where('purchase_order_id', $order->id)
            ->get(['item_id', 'base_quantity']);

        foreach ($lines as $line) {
            $qtyByItem[(string) $line->item_id] = StockPipelineQuantities::qty($line->base_quantity);
        }

        $this->assertWarehouseLines((int) $order->warehouse_id, $qtyByItem);
    }

    /**
     * @param  array<string, string>  $baseQtyByItemId  item id => this document base qty
     */
    public function assertWarehouseLines(int $warehouseId, array $baseQtyByItemId): void
    {
        if ($warehouseId <= 0 || $baseQtyByItemId === []) {
            return;
        }

        $itemIds = array_keys($baseQtyByItemId);

        $ceilings = ItemWarehouseReplenishment::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (ItemWarehouseReplenishment $row): string => (string) $row->item_id);

        $trackedIds = Item::query()
            ->whereIn('id', $itemIds)
            ->where('track_inventory', true)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $pairs = [];
        foreach ($trackedIds as $itemId) {
            $rule = $ceilings->get($itemId);
            if ($rule === null || ! PurchaseOrderMaxQuantity::hasCeiling($rule->max_qty)) {
                continue;
            }
            $pairs[] = [$itemId, $warehouseId];
        }

        if ($pairs === []) {
            return;
        }

        $onHandByItem = StockBalance::query()
            ->select('item_id', DB::raw('SUM(quantity) as quantity'))
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', array_column($pairs, 0))
            ->groupBy('item_id')
            ->pluck('quantity', 'item_id');

        $pipeline = $this->pipeline->mapForPairs($pairs);

        foreach ($pairs as [$itemId, $pairWarehouseId]) {
            $rule = $ceilings->get($itemId);
            if ($rule === null) {
                continue;
            }

            $key = StockPipelineQuantities::pairKey($itemId, $pairWarehouseId);
            $pipelineRow = $pipeline[$key] ?? StockPipelineQuantities::empty();
            $committed = StockPipelineQuantities::compose(
                StockPipelineQuantities::qty($onHandByItem[$itemId] ?? 0),
                $pipelineRow['on_order_qty'],
                $pipelineRow['in_transit_in_qty'],
                $pipelineRow['in_transit_out_qty'],
            )['projected_qty'];

            $projected = PurchaseOrderMaxQuantity::projected(
                $committed,
                $baseQtyByItemId[$itemId] ?? '0',
            );

            if (PurchaseOrderMaxQuantity::exceedsCeiling($projected, $rule->max_qty)) {
                abort(422, 'Ordered quantity would exceed the max stock for this item in the selected warehouse.', [
                    'X-Error-Code' => 'PURCHASE_ORDER_QTY_EXCEEDS_MAX',
                ]);
            }
        }
    }
}
