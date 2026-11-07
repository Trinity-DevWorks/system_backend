<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\DTOs;

use App\Modules\Sales\PriceList\Models\PriceList;
use App\Modules\Sales\PriceList\Models\PriceListItem;

final class PriceListResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(PriceList $list): array
    {
        return [
            'id' => (int) $list->id,
            'code' => $list->code,
            'name' => $list->name,
            'name_ar' => $list->name_ar,
            'currency_id' => (int) $list->currency_id,
            'currency_code' => $list->relationLoaded('currency') ? $list->currency?->code : null,
            'valid_from' => $list->valid_from?->toDateString(),
            'valid_to' => $list->valid_to?->toDateString(),
            'is_default' => (bool) $list->is_default,
            'is_active' => (bool) $list->is_active,
            'items_count' => isset($list->items_count) ? (int) $list->items_count : null,
            'created_at' => (string) $list->created_at,
            'updated_at' => (string) $list->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(PriceList $list): array
    {
        return [
            ...self::summary($list),
            'items' => $list->relationLoaded('items')
                ? $list->items->map(fn (PriceListItem $row): array => [
                    'id' => (int) $row->id,
                    'item_id' => (string) $row->item_id,
                    'item_code' => $row->item?->item_code,
                    'item_name' => $row->item?->name,
                    'item_uom_id' => (int) $row->item_uom_id,
                    'uom_code' => $row->itemUom?->uom?->code,
                    'uom_name' => $row->itemUom?->uom?->name,
                    'min_quantity' => (string) $row->min_quantity,
                    'price' => (string) $row->price,
                ])->values()->all()
                : [],
        ];
    }
}
