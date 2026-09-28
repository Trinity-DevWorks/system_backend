<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\DTOs;

use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use Illuminate\Support\Collection;

readonly class PurchaseInvoiceLineResponseData
{
    /**
     * @param  Collection<int, PurchaseInvoiceLine>  $lines
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines->map(fn (PurchaseInvoiceLine $line): array => self::fromModel($line))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromModel(PurchaseInvoiceLine $line): array
    {
        $line->loadMissing(['item', 'itemUom.uom', 'warehouse', 'lot', 'goodsReceiptLine', 'purchaseOrderLine']);
        $grn = $line->goodsReceiptLine;
        $poLine = $line->purchaseOrderLine;
        $sourceQty = $grn !== null
            ? (string) $grn->quantity
            : ($poLine !== null ? (string) $poLine->quantity : null);
        $sourcePrice = $grn !== null
            ? ($grn->unit_cost !== null ? (string) $grn->unit_cost : null)
            : ($poLine !== null && $poLine->unit_price !== null ? (string) $poLine->unit_price : null);
        $qtyMismatch = $sourceQty !== null && bccomp((string) $line->quantity, $sourceQty, 6) !== 0;
        $priceMismatch = $sourcePrice !== null && bccomp((string) $line->unit_price, $sourcePrice, 4) !== 0;

        return [
            'id' => $line->id,
            'goods_receipt_line_id' => $line->goods_receipt_line_id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'item_id' => $line->item_id,
            'item_uom_id' => $line->item_uom_id,
            'warehouse_id' => $line->warehouse_id,
            'lot_id' => $line->lot_id,
            'sort_order' => $line->sort_order,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'conversion_factor' => (string) $line->conversion_factor,
            'unit_price' => (string) $line->unit_price,
            'discount_percent' => (string) $line->discount_percent,
            'discount_amount' => (string) $line->discount_amount,
            'tax_rate' => (string) $line->tax_rate,
            'line_subtotal' => (string) $line->line_subtotal,
            'tax_amount' => (string) $line->tax_amount,
            'line_total' => (string) $line->line_total,
            'description' => $line->description,
            'notes' => $line->notes,
            'grn_quantity' => $grn ? (string) $grn->quantity : null,
            'grn_unit_cost' => $grn?->unit_cost !== null ? (string) $grn->unit_cost : null,
            'po_quantity' => $poLine ? (string) $poLine->quantity : null,
            'po_unit_price' => $poLine?->unit_price !== null ? (string) $poLine->unit_price : null,
            'qty_mismatch' => $qtyMismatch,
            'price_mismatch' => $priceMismatch,
            'item' => $line->item ? [
                'id' => $line->item->id,
                'item_code' => $line->item->item_code,
                'name' => $line->item->name,
                'track_inventory' => (bool) $line->item->track_inventory,
                'track_lots' => (bool) $line->item->track_lots,
            ] : null,
            'warehouse' => $line->warehouse ? [
                'id' => $line->warehouse->id,
                'name' => $line->warehouse->name,
                'shortcut_name' => $line->warehouse->shortcut_name,
            ] : null,
            'lot' => $line->lot ? [
                'id' => $line->lot->id,
                'lot_number' => $line->lot->lot_number,
                'expiry_date' => $line->lot->expiry_date?->toDateString(),
            ] : null,
        ];
    }
}
