<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Inventory\Purchasing\Support\GoodsReceiptRules;
use App\Modules\Purchasing\PurchaseInvoice\Support\PurchaseInvoiceRules;
use Illuminate\Support\Collection;

readonly class GoodsReceiptLineResponseData
{
    /**
     * @param  array<int, string>|null  $invoicedBaseByLineId
     */
    public static function fromModel(GoodsReceiptLine $line, ?array $invoicedBaseByLineId = null): array
    {
        $line->loadMissing([
            'item:id,item_code,name,is_active,allow_purchase,track_inventory,track_lots',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
            'lot:id,lot_number,expiry_date',
            'purchaseOrderLine:id,quantity,received_quantity,item_uom_id,unit_price',
        ]);

        $lot = $line->lot;
        $poLine = $line->purchaseOrderLine;
        $openToInvoice = $invoicedBaseByLineId === null
            ? PurchaseInvoiceRules::openQuantity($line)
            : PurchaseInvoiceRules::openQuantityUsingMap($line, $invoicedBaseByLineId);

        return [
            'id' => $line->id,
            'goods_receipt_id' => $line->goods_receipt_id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'item_id' => $line->item_id,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'item_uom_id' => $line->item_uom_id,
            'unit_cost' => $line->unit_cost !== null ? (string) $line->unit_cost : null,
            'lot_id' => $line->lot_id,
            'lot' => $lot ? [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'is_expired' => $lot->isExpired(),
            ] : null,
            'notes' => $line->notes,
            'open_quantity' => $poLine ? GoodsReceiptRules::openQuantity($poLine) : null,
            'open_to_invoice_quantity' => $openToInvoice,
            'item' => self::itemBrief($line->item),
            'item_uom' => $line->itemUom ? [
                'id' => $line->itemUom->id,
                'conversion_factor' => (string) $line->itemUom->conversion_factor,
                'uom' => $line->itemUom->uom ? [
                    'id' => $line->itemUom->uom->id,
                    'code' => $line->itemUom->uom->code,
                    'name' => $line->itemUom->uom->name,
                ] : null,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        $ids = $lines
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $invoiced = PurchaseInvoiceRules::invoicedBaseByGoodsReceiptLineIds($ids);

        return $lines
            ->map(fn (GoodsReceiptLine $line): array => self::fromModel($line, $invoiced))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function itemBrief(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }

        return [
            'id' => $item->id,
            'item_code' => $item->item_code,
            'name' => $item->name,
            'is_active' => (bool) $item->is_active,
            'allow_purchase' => (bool) $item->allow_purchase,
            'track_inventory' => (bool) $item->track_inventory,
            'track_lots' => (bool) $item->track_lots,
        ];
    }
}
