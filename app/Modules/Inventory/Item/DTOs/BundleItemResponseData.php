<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Support\Collection;

readonly class BundleItemResponseData
{
    public static function fromModel(BundleItem $row): array
    {
        $row->loadMissing([
            'childItem:id,sku,name,is_active,item_type_id',
            'childItem.itemType:id,code,name',
        ]);

        return [
            'id' => $row->id,
            'bundle_item_id' => $row->bundle_item_id,
            'child_item_id' => $row->child_item_id,
            'quantity' => (string) $row->quantity,
            'child_item' => self::itemBrief($row->childItem),
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @param  Collection<int, BundleItem>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (BundleItem $row): array => self::fromModel($row))
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
            'sku' => $item->sku,
            'name' => $item->name,
            'is_active' => (bool) $item->is_active,
            'item_type' => $item->itemType ? [
                'id' => $item->itemType->id,
                'code' => $item->itemType->code,
                'name' => $item->itemType->name,
            ] : null,
        ];
    }
}
