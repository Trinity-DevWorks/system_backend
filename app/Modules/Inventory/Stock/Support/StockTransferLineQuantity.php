<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferLine;

final class StockTransferLineQuantity
{
    /**
     * @return array{quantity:string,base_quantity:string,item_uom_id:?int}
     */
    public static function resolve(Item $item, float $quantity, ?int $itemUomId): array
    {
        if ($quantity <= 0) {
            abort(422, 'Transfer line quantity must be greater than zero.', ['X-Error-Code' => 'STOCK_TRANSFER_LINE_INVALID_QUANTITY']);
        }

        $formattedQty = number_format($quantity, 6, '.', '');

        if ($itemUomId === null) {
            return [
                'quantity' => $formattedQty,
                'base_quantity' => $formattedQty,
                'item_uom_id' => null,
            ];
        }

        $itemUom = ItemUom::query()
            ->where('item_id', $item->id)
            ->whereKey($itemUomId)
            ->first();

        if (! $itemUom) {
            abort(422, 'Item UOM does not belong to this item.', ['X-Error-Code' => 'STOCK_TRANSFER_ITEM_UOM_MISMATCH']);
        }

        $baseQuantity = bcmul($formattedQty, (string) $itemUom->conversion_factor, 6);

        if (bccomp($baseQuantity, '0', 6) <= 0) {
            abort(422, 'Transfer line base quantity must be greater than zero.', ['X-Error-Code' => 'STOCK_TRANSFER_LINE_INVALID_BASE_QUANTITY']);
        }

        return [
            'quantity' => $formattedQty,
            'base_quantity' => $baseQuantity,
            'item_uom_id' => (int) $itemUom->id,
        ];
    }

    public static function openQuantity(StockTransferLine $line): string
    {
        $open = bcsub((string) $line->quantity, self::allocatedQuantity($line), 6);

        return bccomp($open, '0', 6) < 0 ? '0.000000' : $open;
    }

    public static function openBaseQuantity(StockTransferLine $line): string
    {
        $open = bcsub((string) $line->base_quantity, self::allocatedBaseQuantity($line), 6);

        return bccomp($open, '0', 6) < 0 ? '0.000000' : $open;
    }

    public static function allocatedQuantity(StockTransferLine $line): string
    {
        $allocated = bcadd((string) $line->received_quantity, (string) $line->returned_quantity, 6);

        return bcadd($allocated, (string) $line->written_off_quantity, 6);
    }

    public static function allocatedBaseQuantity(StockTransferLine $line): string
    {
        $allocated = bcadd((string) $line->received_base_quantity, (string) $line->returned_base_quantity, 6);

        return bcadd($allocated, (string) $line->written_off_base_quantity, 6);
    }

    public static function baseForQuantity(StockTransferLine $line, string $quantity): string
    {
        $openQty = self::openQuantity($line);
        if (bccomp($quantity, $openQty, 6) === 0) {
            return self::openBaseQuantity($line);
        }

        $lineQty = (string) $line->quantity;
        if (bccomp($lineQty, '0', 6) <= 0) {
            return '0.000000';
        }

        return bcmul($quantity, bcdiv((string) $line->base_quantity, $lineQty, 8), 6);
    }

    public static function hasOpenQuantity(StockTransfer $transfer): bool
    {
        $lines = $transfer->relationLoaded('lines')
            ? $transfer->lines
            : $transfer->lines()->get();

        foreach ($lines as $line) {
            if (bccomp(self::openQuantity($line), '0', 6) > 0) {
                return true;
            }
        }

        return false;
    }

    public static function hasAllocatedQuantities(StockTransfer $transfer): bool
    {
        $lines = $transfer->relationLoaded('lines')
            ? $transfer->lines
            : $transfer->lines()->get();

        foreach ($lines as $line) {
            if (bccomp(self::allocatedQuantity($line), '0', 6) > 0
                || bccomp(self::allocatedBaseQuantity($line), '0', 6) > 0) {
                return true;
            }
        }

        return false;
    }
}
