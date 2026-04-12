<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Models\ItemUnitOfMeasurement;

readonly class ItemUomPivotResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromPivot(ItemUnitOfMeasurement $pivot): array
    {
        $pivot->loadMissing('unitOfMeasurement:id,code,name,unit_group_id');

        $uom = $pivot->unitOfMeasurement;

        return [
            'id' => $pivot->id,
            'unit_of_measurement' => $uom ? [
                'id' => $uom->id,
                'code' => $uom->code,
                'name' => $uom->name,
                'unit_group_id' => $uom->unit_group_id,
            ] : null,
            'operation' => $pivot->operation,
            'conversion' => (string) $pivot->conversion,
            'price_1' => $pivot->price_1 !== null ? (string) $pivot->price_1 : null,
            'price_2' => $pivot->price_2 !== null ? (string) $pivot->price_2 : null,
            'price_3' => $pivot->price_3 !== null ? (string) $pivot->price_3 : null,
            'price_4' => $pivot->price_4 !== null ? (string) $pivot->price_4 : null,
            'price_5' => $pivot->price_5 !== null ? (string) $pivot->price_5 : null,
            'price_6' => $pivot->price_6 !== null ? (string) $pivot->price_6 : null,
            'gross_volume' => $pivot->gross_volume !== null ? (string) $pivot->gross_volume : null,
            'gross_weight' => $pivot->gross_weight !== null ? (string) $pivot->gross_weight : null,
            'net_volume' => $pivot->net_volume !== null ? (string) $pivot->net_volume : null,
            'net_weight' => $pivot->net_weight !== null ? (string) $pivot->net_weight : null,
            'created_at' => (string) $pivot->created_at,
            'updated_at' => (string) $pivot->updated_at,
        ];
    }
}
