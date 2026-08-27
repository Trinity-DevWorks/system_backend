<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

final class StockPipelineQuantities
{
    public static function pairKey(string $itemId, int $warehouseId): string
    {
        return $itemId.'|'.$warehouseId;
    }

    /**
     * @return array{on_order_qty: string, in_transit_in_qty: string, in_transit_out_qty: string, projected_qty: string}
     */
    public static function empty(): array
    {
        return self::compose('0', '0', '0', '0');
    }

    /**
     * Warehouse-level incoming (open PO + inbound transfer). On-hand is not reduced by in-transit out.
     *
     * @return array{on_order_qty: string, in_transit_in_qty: string, in_transit_out_qty: string, projected_qty: string}
     */
    public static function compose(
        string $onHand,
        string $onOrder,
        string $inTransitIn,
        string $inTransitOut,
    ): array {
        $onOrderQty = self::qty($onOrder);
        $inTransitInQty = self::qty($inTransitIn);
        $inTransitOutQty = self::qty($inTransitOut);
        $projected = bcadd(bcadd(self::qty($onHand), $onOrderQty, 6), $inTransitInQty, 6);

        return [
            'on_order_qty' => $onOrderQty,
            'in_transit_in_qty' => $inTransitInQty,
            'in_transit_out_qty' => $inTransitOutQty,
            'projected_qty' => $projected,
        ];
    }

    public static function qty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.000000';
        }

        return number_format((float) $value, 6, '.', '');
    }
}
