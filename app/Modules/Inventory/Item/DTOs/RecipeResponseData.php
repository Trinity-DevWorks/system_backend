<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Models\Recipe;

readonly class RecipeResponseData
{
    public static function fromModel(Recipe $recipe, bool $includeItems = true): array
    {
        $recipe->loadMissing([
            'item:id,sku,name,is_active,item_type_id,base_uom_id',
            'item.itemType:id,code,name',
            'uom:id,code,name',
        ]);

        $payload = [
            'id' => $recipe->id,
            'item_id' => $recipe->item_id,
            'yield_quantity' => (string) $recipe->yield_quantity,
            'uom_id' => $recipe->uom_id,
            'uom' => $recipe->uom ? [
                'id' => $recipe->uom->id,
                'code' => $recipe->uom->code,
                'name' => $recipe->uom->name,
            ] : null,
            'item' => $recipe->item ? [
                'id' => $recipe->item->id,
                'sku' => $recipe->item->sku,
                'name' => $recipe->item->name,
                'is_active' => (bool) $recipe->item->is_active,
                'item_type' => $recipe->item->itemType ? [
                    'id' => $recipe->item->itemType->id,
                    'code' => $recipe->item->itemType->code,
                    'name' => $recipe->item->itemType->name,
                ] : null,
            ] : null,
            'created_at' => (string) $recipe->created_at,
            'updated_at' => (string) $recipe->updated_at,
        ];

        if ($includeItems) {
            $recipe->loadMissing([
                'recipeItems.ingredientItem.itemType',
                'recipeItems.uom',
            ]);
            $payload['ingredients'] = RecipeItemResponseData::collectionToArray($recipe->recipeItems);
        }

        return $payload;
    }
}
