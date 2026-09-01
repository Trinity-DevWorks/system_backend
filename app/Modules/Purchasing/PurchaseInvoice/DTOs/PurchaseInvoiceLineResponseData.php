<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoiceLine;
use Illuminate\Support\Collection;

readonly class PurchaseInvoiceLineResponseData
{
    public static function fromModel(PurchaseInvoiceLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,is_active,allow_purchase,vat_group_id',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
            'vatGroup:id,abrv,name,percentage',
        ]);

        return [
            'id' => $line->id,
            'purchase_invoice_id' => $line->purchase_invoice_id,
            'item_id' => $line->item_id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'goods_receipt_line_id' => $line->goods_receipt_line_id,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'item_uom_id' => $line->item_uom_id,
            'unit_price' => (string) $line->unit_price,
            'vat_group_id' => $line->vat_group_id,
            'tax_rate' => (string) $line->tax_rate,
            'line_subtotal' => (string) $line->line_subtotal,
            'tax_amount' => (string) $line->tax_amount,
            'line_total' => (string) $line->line_total,
            'notes' => $line->notes,
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
            'vat_group' => $line->vatGroup ? [
                'id' => $line->vatGroup->id,
                'abrv' => $line->vatGroup->abrv,
                'name' => $line->vatGroup->name,
                'percentage' => (string) $line->vatGroup->percentage,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, PurchaseInvoiceLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (PurchaseInvoiceLine $line): array => self::fromModel($line))
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
            'vat_group_id' => $item->vat_group_id,
        ];
    }
}
