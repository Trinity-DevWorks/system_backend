<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Item\Support\ItemUomValidation;
use App\Modules\Inventory\Item\Support\RecipeRules;
use Illuminate\Support\Facades\DB;

class RecipeService
{
    public function findForItem(Item $item): ?Recipe
    {
        return Recipe::query()
            ->where('item_id', $item->id)
            ->with([
                'uom:id,code,name',
                'recipeItems.ingredientItem.itemType:id,code,name',
                'recipeItems.uom:id,code,name',
            ])
            ->first();
    }

    public function getForItem(Item $item): Recipe
    {
        $recipe = $this->findForItem($item);
        if (! $recipe) {
            abort(404, 'Recipe not found for this item.', ['X-Error-Code' => 'RECIPE_NOT_FOUND']);
        }

        return $recipe;
    }

    /**
     * @param  array{yield_quantity:numeric,uom_id:int}  $data
     */
    public function upsertForItem(Item $item, array $data): Recipe
    {
        RecipeRules::assertIsProducedItem($item);
        ItemUomValidation::assertUomAllowedForItem($item, (int) $data['uom_id']);

        return DB::transaction(function () use ($item, $data): Recipe {
            $recipe = Recipe::query()->firstOrNew(['item_id' => $item->id]);
            $recipe->fill([
                'yield_quantity' => number_format((float) $data['yield_quantity'], 6, '.', ''),
                'uom_id' => (int) $data['uom_id'],
            ]);
            $recipe->save();

            return $recipe->load([
                'uom:id,code,name',
                'item.itemType:id,code,name',
                'recipeItems.ingredientItem.itemType:id,code,name',
                'recipeItems.uom:id,code,name',
            ]);
        });
    }

    public function ensureForItem(Item $item): Recipe
    {
        RecipeRules::assertIsProducedItem($item);

        $existing = $this->findForItem($item);
        if ($existing) {
            return $existing;
        }

        if (! $item->base_uom_id) {
            abort(422, 'Produced item must have a base UOM before creating a recipe.', ['X-Error-Code' => 'RECIPE_MISSING_BASE_UOM']);
        }

        return $this->upsertForItem($item, [
            'yield_quantity' => 1,
            'uom_id' => (int) $item->base_uom_id,
        ]);
    }
}
