<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class SalesInvoiceLineResponseData
{
    public static function fromModel(SalesInvoiceLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,description,is_active,allow_sale,track_inventory,track_lots,item_type_id,vat_group_id',
            'item.itemType:id,code',
            'item.vatGroup:id,percentage',
            'itemUom:id,uom_id,conversion_factor,selling_price,barcode',
            'itemUom.uom:id,code,name',
            'warehouse:id,name,shortcut_name,is_active',
            'lot:id,lot_number,expiry_date,item_id',
        ]);

        return [
            'id' => $line->id,
            'sales_invoice_id' => $line->sales_invoice_id,
            'item_id' => $line->item_id,
            'item_uom_id' => $line->item_uom_id,
            'warehouse_id' => $line->warehouse_id,
            'lot_id' => $line->lot_id,
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
            'warehouse' => self::warehouseBrief($line->warehouse),
            'lot' => $line->lot ? [
                'id' => $line->lot->id,
                'lot_number' => $line->lot->lot_number,
                'expiry_date' => $line->lot->expiry_date?->toDateString(),
            ] : null,
            'created_at' => (string) $line->created_at,
            'updated_at' => (string) $line->updated_at,
        ];
    }

    /**
     * @param  Collection<int, SalesInvoiceLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (SalesInvoiceLine $line): array => self::fromModel($line))
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
            'description' => $item->description,
            'is_active' => (bool) $item->is_active,
            'allow_sale' => (bool) $item->allow_sale,
            'track_inventory' => (bool) $item->track_inventory,
            'track_lots' => (bool) $item->track_lots,
            'item_type_code' => $item->itemType?->code,
            'vat_group_id' => $item->vat_group_id !== null ? (int) $item->vat_group_id : null,
            'vat_group' => $item->vatGroup
                ? [
                    'id' => $item->vatGroup->id,
                    'percentage' => (string) $item->vatGroup->percentage,
                ]
                : null,
        ];
    }

    /**
     * @return array{id:int,name:string,shortcut_name:?string,is_active:bool}|null
     */
    private static function warehouseBrief(?Warehouse $warehouse): ?array
    {
        if (! $warehouse) {
            return null;
        }

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'shortcut_name' => $warehouse->shortcut_name,
            'is_active' => (bool) $warehouse->is_active,
        ];
    }
}
