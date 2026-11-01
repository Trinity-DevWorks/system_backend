<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Support\Decimal;

final class StockPipelineQuantities
{
    public static function pairKey(string $itemId, int $warehouseId): string
    {
        return $itemId.'|'.$warehouseId;
    }

    /**
     * @return array{on_order_qty: string, in_transit_in_qty: string, in_transit_out_qty: string, reserved_qty: string, available_qty: string, projected_qty: string}
     */
    public static function empty(): array
    {
        return self::compose('0', '0', '0', '0');
    }

    /**
     * Warehouse-level incoming (open PO + inbound transfer) minus stock reserved by confirmed sales orders.
     * On-hand is not reduced by in-transit out. available = on hand - reserved.
     *
     * @return array{on_order_qty: string, in_transit_in_qty: string, in_transit_out_qty: string, reserved_qty: string, available_qty: string, projected_qty: string}
     */
    public static function compose(
        string $onHand,
        string $onOrder,
        string $inTransitIn,
        string $inTransitOut,
        string $reserved = '0',
    ): array {
        $onOrderQty = self::qty($onOrder);
        $inTransitInQty = self::qty($inTransitIn);
        $inTransitOutQty = self::qty($inTransitOut);
        $reservedQty = self::qty($reserved);
        $projected = bcsub(bcadd(bcadd(self::qty($onHand), $onOrderQty, 6), $inTransitInQty, 6), $reservedQty, 6);

        return [
            'on_order_qty' => $onOrderQty,
            'in_transit_in_qty' => $inTransitInQty,
            'in_transit_out_qty' => $inTransitOutQty,
            'reserved_qty' => $reservedQty,
            'available_qty' => bcsub(self::qty($onHand), $reservedQty, 6),
            'projected_qty' => $projected,
        ];
    }

    public static function qty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.000000';
        }

        return Decimal::round($value, 6);
    }
}
