<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\RecipeItem;
use Illuminate\Support\Collection;

readonly class RecipeItemResponseData
{
    public static function fromModel(RecipeItem $row): array
    {
        $row->loadMissing([
            'ingredientItem:id,sku,name,is_active,item_type_id,base_uom_id',
            'ingredientItem.itemType:id,code,name',
            'uom:id,code,name',
        ]);

        return [
            'id' => $row->id,
            'recipe_id' => $row->recipe_id,
            'item_id' => $row->item_id,
            'quantity' => (string) $row->quantity,
            'uom_id' => $row->uom_id,
            'uom' => $row->uom ? [
                'id' => $row->uom->id,
                'code' => $row->uom->code,
                'name' => $row->uom->name,
            ] : null,
            'ingredient_item' => self::itemBrief($row->ingredientItem),
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @param  Collection<int, RecipeItem>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (RecipeItem $row): array => self::fromModel($row))
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
