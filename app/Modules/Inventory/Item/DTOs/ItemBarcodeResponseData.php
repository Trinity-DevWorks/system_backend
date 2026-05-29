<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Support\Collection;

readonly class ItemBarcodeResponseData
{
    public static function fromModel(ItemBarcode $barcode): array
    {
        $barcode->loadMissing([
            'itemUom:id,item_id,uom_id,currency_id,conversion_factor,is_base',
            'itemUom.uom:id,code,name',
            'itemUom.currency:id,code,symbol',
        ]);

        return [
            'id' => $barcode->id,
            'item_id' => $barcode->item_id,
            'barcode' => $barcode->barcode,
            'item_uom_id' => $barcode->item_uom_id,
            'item_uom' => self::itemUomBrief($barcode->itemUom),
            'is_primary' => (bool) $barcode->is_primary,
            'created_at' => (string) $barcode->created_at,
            'updated_at' => (string) $barcode->updated_at,
        ];
    }

    /**
     * @param  Collection<int, ItemBarcode>  $barcodes
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $barcodes): array
    {
        return $barcodes
            ->map(fn (ItemBarcode $barcode): array => self::fromModel($barcode))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function itemUomBrief(?ItemUom $itemUom): ?array
    {
        if (! $itemUom) {
            return null;
        }

        $itemUom->loadMissing(['uom:id,code,name', 'currency:id,code,symbol']);

        return [
            'id' => $itemUom->id,
            'uom' => $itemUom->uom ? [
                'id' => $itemUom->uom->id,
                'code' => $itemUom->uom->code,
                'name' => $itemUom->uom->name,
            ] : null,
            'currency' => $itemUom->currency ? [
                'id' => $itemUom->currency->id,
                'code' => $itemUom->currency->code,
                'symbol' => $itemUom->currency->symbol,
            ] : null,
            'conversion_factor' => (string) $itemUom->conversion_factor,
            'is_base' => (bool) $itemUom->is_base,
        ];
    }
}
