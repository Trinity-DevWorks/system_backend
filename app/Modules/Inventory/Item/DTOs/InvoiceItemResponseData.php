<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Support\Collection;

/**
 * Slim item + line-setup payloads for sales/purchase invoice drawers.
 */
readonly class InvoiceItemResponseData
{
    /**
     * Search hit for invoice item typeahead.
     *
     * @return array<string, mixed>
     */
    public static function searchHit(Item $item): array
    {
        $item->loadMissing(['vatGroup:id,percentage']);

        return [
            'id' => $item->id,
            'item_code' => $item->item_code,
            'name' => $item->name,
            'track_inventory' => (bool) $item->track_inventory,
            'track_lots' => (bool) $item->track_lots,
            'allow_sale' => (bool) $item->allow_sale,
            'allow_purchase' => (bool) $item->allow_purchase,
            'is_active' => (bool) $item->is_active,
            'vat_percentage' => $item->vatGroup?->percentage !== null
                ? (string) $item->vatGroup->percentage
                : null,
            'vat_group' => $item->vatGroup
                ? [
                    'id' => $item->vatGroup->id,
                    'percentage' => (string) $item->vatGroup->percentage,
                ]
                : null,
        ];
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array<string, mixed>>
     */
    public static function searchCollection(Collection $items): array
    {
        return $items->map(fn (Item $item): array => self::searchHit($item))->values()->all();
    }

    /**
     * UOMs with resolved barcode + prices for one selected invoice line item.
     *
     * @return array{item_id: string, item_uoms: list<array<string, mixed>>}
     */
    public static function lineSetup(Item $item): array
    {
        $item->loadMissing([
            'itemUoms.uom:id,code,name,unit_group_id',
            'itemUoms.barcodes:id,item_uom_id,barcode,is_primary',
            'barcodes:id,item_id,item_uom_id,barcode,is_primary',
        ]);

        /** @var Collection<int, ItemBarcode> $itemBarcodes */
        $itemBarcodes = $item->barcodes;

        $uoms = $item->itemUoms
            ->sortBy(fn (ItemUom $row): array => [
                $row->is_base ? 0 : 1,
                (string) ($row->uom?->code ?? ''),
            ])
            ->values()
            ->map(fn (ItemUom $row): array => self::lineUom($row, $itemBarcodes))
            ->all();

        return [
            'item_id' => $item->id,
            'item_uoms' => $uoms,
        ];
    }

    /**
     * @param  Collection<int, ItemBarcode>  $itemBarcodes
     * @return array<string, mixed>
     */
    private static function lineUom(ItemUom $row, Collection $itemBarcodes): array
    {
        $barcode = self::resolveBarcode($row, $itemBarcodes);

        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'conversion_factor' => (string) $row->conversion_factor,
            'barcode' => $barcode,
            'selling_price' => $row->selling_price !== null ? (string) $row->selling_price : null,
            'cost_price' => $row->cost_price !== null ? (string) $row->cost_price : null,
            'is_base' => (bool) $row->is_base,
            'is_default_sale' => (bool) $row->is_default_sale,
            'is_default_purchase' => (bool) $row->is_default_purchase,
            'uom' => $row->uom ? [
                'id' => $row->uom->id,
                'code' => $row->uom->code,
                'name' => $row->uom->name,
                'unit_group_id' => $row->uom->unit_group_id,
            ] : null,
        ];
    }

    /**
     * Prefer UOM column barcode, else primary item_barcodes row for that UOM, else any.
     *
     * @param  Collection<int, ItemBarcode>  $itemBarcodes
     */
    private static function resolveBarcode(ItemUom $row, Collection $itemBarcodes): ?string
    {
        if (is_string($row->barcode) && trim($row->barcode) !== '') {
            return trim($row->barcode);
        }

        $forUom = $itemBarcodes->filter(
            fn (ItemBarcode $b): bool => (int) $b->item_uom_id === (int) $row->id
        );
        $primary = $forUom->first(fn (ItemBarcode $b): bool => (bool) $b->is_primary) ?? $forUom->first();
        if ($primary && is_string($primary->barcode) && trim($primary->barcode) !== '') {
            return trim($primary->barcode);
        }

        return null;
    }
}
