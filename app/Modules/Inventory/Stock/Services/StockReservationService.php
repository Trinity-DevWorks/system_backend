<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What: Stock reserved by confirmed sales orders, and the checks that protect it.
 * How:  Reserved quantity is derived, not stored: for every open order (confirmed or partially
 *       invoiced) each line reserves base_quantity - delivered_base_quantity in the order's warehouse
 *       (delivered = shipped by a delivery note or by a stock-moving invoice).
 *       Bundle lines reserve their components. Posting an invoice, reversing it, cancelling or
 *       closing the order therefore updates reservations automatically.
 * Where: SalesOrderService::confirm() (assertOrderCanReserve) and StockMovementService::post()
 *        (assertOutboundRespectsReservations); availability screens use reservedFor()/reservedQuery().
 */
class StockReservationService
{
    /** @return list<string> */
    public static function reservingStatuses(): array
    {
        return [SalesOrderStatus::Confirmed->value, SalesOrderStatus::PartiallyInvoiced->value];
    }

    /**
     * Rows of (item_id, warehouse_id, reserved_qty) for all open orders, optionally excluding one order.
     */
    public function reservedQuery(?string $excludeOrderId = null): Builder
    {
        $statuses = self::reservingStatuses();

        $direct = DB::table('sales_order_lines as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->whereIn('o.status', $statuses)
            ->where('i.track_inventory', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('bundle_items as bx')->whereColumn('bx.bundle_item_id', 'l.item_id'))
            ->when($excludeOrderId !== null, fn ($q) => $q->where('o.id', '!=', $excludeOrderId))
            ->selectRaw('l.item_id as item_id, o.warehouse_id as warehouse_id, (l.base_quantity - l.delivered_base_quantity) as qty');

        $components = DB::table('sales_order_lines as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->join('bundle_items as b', 'b.bundle_item_id', '=', 'l.item_id')
            ->join('items as ci', 'ci.id', '=', 'b.child_item_id')
            ->whereIn('o.status', $statuses)
            ->where('ci.track_inventory', true)
            ->when($excludeOrderId !== null, fn ($q) => $q->where('o.id', '!=', $excludeOrderId))
            ->selectRaw('b.child_item_id as item_id, o.warehouse_id as warehouse_id, (l.base_quantity - l.delivered_base_quantity) * b.quantity as qty');

        return DB::query()
            ->fromSub($direct->unionAll($components), 'r')
            ->selectRaw('r.item_id, r.warehouse_id, SUM(r.qty) as reserved_qty')
            ->groupBy('r.item_id', 'r.warehouse_id')
            ->havingRaw('SUM(r.qty) > 0');
    }

    public function reservedFor(string $itemId, int $warehouseId, ?string $excludeOrderId = null): string
    {
        $value = DB::query()
            ->fromSub($this->reservedQuery($excludeOrderId), 'rv')
            ->where('rv.item_id', $itemId)
            ->where('rv.warehouse_id', $warehouseId)
            ->value('reserved_qty');

        return Decimal::round($value ?? 0, 6);
    }

    /**
     * Confirming an order reserves its remaining quantities; refuse when the warehouse cannot cover them
     * (on hand minus what other open orders already reserved), unless negative stock is allowed.
     */
    public function assertOrderCanReserve(SalesOrder $order): void
    {
        if (CompanySetting::current()->allowsNegativeStock()) {
            return;
        }

        $warehouseId = (int) $order->warehouse_id;
        $needs = DB::query()
            ->fromSub($this->reservedQuery(), 'rv')
            ->whereIn('rv.item_id', function ($q) use ($order) {
                $q->select('l.item_id')->from('sales_order_lines as l')->where('l.sales_order_id', $order->id)
                    ->union(
                        DB::table('sales_order_lines as l2')
                            ->join('bundle_items as b2', 'b2.bundle_item_id', '=', 'l2.item_id')
                            ->where('l2.sales_order_id', $order->id)
                            ->select('b2.child_item_id')
                    );
            })
            ->where('rv.warehouse_id', $warehouseId)
            ->pluck('reserved_qty', 'item_id');

        foreach ($needs as $itemId => $reservedIncludingThis) {
            // Serialize with stock movements and other confirmations for this item/warehouse.
            StockBalance::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->lockForUpdate()->get(['id']);

            $onHand = StockBalance::onHandForWarehouse((string) $itemId, $warehouseId);
            if (Decimal::cmp($reservedIncludingThis, $onHand) > 0) {
                $item = Item::query()->find($itemId, ['id', 'item_code', 'name']);
                $mine = Decimal::sub($reservedIncludingThis, $this->reservedFor((string) $itemId, $warehouseId, (string) $order->id));
                $available = Decimal::max('0', Decimal::sub($onHand, Decimal::sub($reservedIncludingThis, $mine)));
                abort(422, sprintf(
                    'Not enough stock to reserve %s: needs %s, available %s (on hand %s).',
                    $item?->item_code ?: ($item?->name ?? (string) $itemId),
                    Decimal::round($mine, 2),
                    Decimal::round($available, 2),
                    Decimal::round($onHand, 2),
                ), ['X-Error-Code' => 'STOCK_INSUFFICIENT_TO_RESERVE']);
            }
        }
    }

    /**
     * An outbound movement may not use stock that confirmed orders reserved (unless negative stock is allowed).
     * Shipments of the reserving order are fine: their quantities leave the reservation before the stock moves.
     *
     * @param  string  $warehouseOnHandBefore  On hand across lots before this movement.
     */
    public function assertOutboundRespectsReservations(Item $item, int $warehouseId, string $warehouseOnHandBefore, string $quantityDelta): void
    {
        if (! Decimal::isNegative($quantityDelta) || CompanySetting::current()->allowsNegativeStock()) {
            return;
        }

        $reserved = $this->reservedFor((string) $item->id, $warehouseId);
        if (! Decimal::isPositive($reserved)) {
            return;
        }

        $after = Decimal::add($warehouseOnHandBefore, $quantityDelta);
        if (Decimal::cmp($after, $reserved) < 0) {
            abort(422, sprintf(
                'Stock of %s is reserved for confirmed sales orders: on hand %s, reserved %s.',
                $item->item_code ?: $item->name,
                Decimal::round($warehouseOnHandBefore, 2),
                Decimal::round($reserved, 2),
            ), ['X-Error-Code' => 'STOCK_RESERVED']);
        }
    }
}
