<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Sales\SalesOrder\Models\SalesOrderLine;
use Illuminate\Support\Collection;

readonly class SalesOrderLineResponseData
{
    public static function fromModel(SalesOrderLine $line): array
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
            'sales_order_id' => $line->sales_order_id,
            'item_id' => $line->item_id,
            'item_uom_id' => $line->item_uom_id,
            'sort_order' => (int) $line->sort_order,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'invoiced_base_quantity' => (string) $line->invoiced_base_quantity,
            'remaining_base_quantity' => $line->remainingBaseQuantity(),
            'remaining_quantity' => $line->remainingQuantity(),
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
     * @param  Collection<int, SalesOrderLine>  $lines
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines->map(fn (SalesOrderLine $line): array => self::fromModel($line))->values()->all();
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
            'track_inventory' => (bool) $item->track_inventory,
            'item_type_code' => $item->itemType?->code,
            'vat_group_id' => $item->vat_group_id !== null ? (int) $item->vat_group_id : null,
            'vat_group' => $item->vatGroup ? [
                'id' => $item->vatGroup->id,
                'percentage' => (string) $item->vatGroup->percentage,
            ] : null,
        ];
    }
}
