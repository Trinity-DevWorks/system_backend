<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotationLine;
use Illuminate\Support\Collection;

readonly class SalesQuotationLineResponseData
{
    public static function fromModel(SalesQuotationLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,description,is_active,allow_sale,track_inventory,track_lots,item_type_id,vat_group_id',
            'item.itemType:id,code',
            'item.vatGroup:id,percentage',
            'itemUom:id,uom_id,conversion_factor,selling_price,barcode',
            'itemUom.uom:id,code,name',
        ]);

        return [
            'id' => $line->id,
            'sales_quotation_id' => $line->sales_quotation_id,
            'item_id' => $line->item_id,
            'item_uom_id' => $line->item_uom_id,
            'sort_order' => (int) $line->sort_order,
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
            'item' => self::itemBrief($line->item),
            'item_uom' => $line->itemUom ? [
                'id' => $line->itemUom->id,
                'conversion_factor' => (string) $line->itemUom->conversion_factor,
                'selling_price' => $line->itemUom->selling_price !== null ? (string) $line->itemUom->selling_price : null,
                'barcode' => $line->itemUom->barcode,
                'uom' => $line->itemUom->uom ? [
                    'id' => $line->itemUom->uom->id,
                    'code' => $line->itemUom->uom->code,
                    'name' => $line->itemUom->uom->name,
                ] : null,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, SalesQuotationLine>  $lines
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines->map(fn (SalesQuotationLine $line): array => self::fromModel($line))->values()->all();
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
            'description' => $item->description,
            'is_active' => (bool) $item->is_active,
            'allow_sale' => (bool) $item->allow_sale,
            'item_type_code' => $item->itemType?->code,
            'vat_group_id' => $item->vat_group_id !== null ? (int) $item->vat_group_id : null,
            'vat_group' => $item->vatGroup ? [
                'id' => $item->vatGroup->id,
                'percentage' => (string) $item->vatGroup->percentage,
            ] : null,
        ];
    }
}
