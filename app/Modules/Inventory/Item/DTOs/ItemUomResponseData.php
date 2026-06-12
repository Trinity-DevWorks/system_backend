<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Support\Collection;

readonly class ItemUomResponseData
{
    public static function fromModel(ItemUom $row): array
    {
        $row->loadMissing(['uom:id,code,name,unit_group_id', 'currency:id,code,name,symbol,iso_code']);

        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'uom' => self::uomBrief($row->uom),
            'currency' => self::currencyBrief($row->currency),
            'conversion_factor' => (string) $row->conversion_factor,
            'barcode' => $row->barcode,
            'selling_price' => $row->selling_price !== null ? (string) $row->selling_price : null,
            'cost_price' => $row->cost_price !== null ? (string) $row->cost_price : null,
            'takeaway_price' => $row->takeaway_price !== null ? (string) $row->takeaway_price : null,
            'dine_in_price' => $row->dine_in_price !== null ? (string) $row->dine_in_price : null,
            'delivery_price' => $row->delivery_price !== null ? (string) $row->delivery_price : null,
            'is_base' => (bool) $row->is_base,
            'is_default_sale' => (bool) $row->is_default_sale,
            'is_default_purchase' => (bool) $row->is_default_purchase,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @param  Collection<int, ItemUom>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (ItemUom $row): array => self::fromModel($row))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,code:string,name:string,unit_group_id:int}|null
     */
    private static function uomBrief(?UnitOfMeasurement $uom): ?array
    {
        if (! $uom) {
            return null;
        }

        return [
            'id' => $uom->id,
            'code' => $uom->code,
            'name' => $uom->name,
            'unit_group_id' => $uom->unit_group_id,
        ];
    }

    /**
     * @return array{id:int,code:string,name:string,symbol:?string,iso_code:string}|null
     */
    private static function currencyBrief(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'iso_code' => $currency->iso_code,
        ];
    }
}
