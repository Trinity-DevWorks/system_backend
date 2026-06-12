<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Item\Models\RecipeItem;
use App\Modules\Inventory\Item\Support\ItemUomValidation;
use App\Modules\Inventory\Item\Support\RecipeRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RecipeItemService
{
    public function __construct(
        private readonly RecipeService $recipeService
    ) {}

    public function listForItem(Item $item): Collection
    {
        $recipe = $this->recipeService->findForItem($item);
        if (! $recipe) {
            return new Collection;
        }

        return $this->listForRecipe($recipe);
    }

    public function listForRecipe(Recipe $recipe): Collection
    {
        return RecipeItem::query()
            ->where('recipe_id', $recipe->id)
            ->with(['ingredientItem.itemType:id,code,name', 'uom:id,code,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{item_id:int,quantity:numeric,uom_id:int}  $data
     */
    public function addIngredient(Item $item, array $data): RecipeItem
    {
        $recipe = $this->recipeService->ensureForItem($item);
        $ingredient = Item::query()->findOrFail($data['item_id']);

        RecipeRules::assertValidIngredient($item, $ingredient);
        ItemUomValidation::assertUomAllowedForItem($ingredient, (int) $data['uom_id']);

        if (RecipeItem::query()->where('recipe_id', $recipe->id)->where('item_id', $data['item_id'])->exists()) {
            abort(422, 'Ingredient is already on this recipe.', ['X-Error-Code' => 'RECIPE_INGREDIENT_DUPLICATE']);
        }

        return DB::transaction(function () use ($recipe, $data): RecipeItem {
            return RecipeItem::query()->create([
                'recipe_id' => $recipe->id,
                'item_id' => $data['item_id'],
                'quantity' => number_format((float) $data['quantity'], 6, '.', ''),
                'uom_id' => (int) $data['uom_id'],
            ])->load(['ingredientItem.itemType', 'uom']);
        });
    }

    /**
     * @param  array{quantity:numeric,uom_id:int}  $data
     */
    public function updateLine(Item $item, RecipeItem $row, array $data): RecipeItem
    {
        $recipe = $this->recipeService->getForItem($item);
        $this->assertScoped($recipe, $row);

        $ingredient = Item::query()->findOrFail($row->item_id);
        RecipeRules::assertValidIngredient($item, $ingredient);
        ItemUomValidation::assertUomAllowedForItem($ingredient, (int) $data['uom_id']);

        $row->update([
            'quantity' => number_format((float) $data['quantity'], 6, '.', ''),
            'uom_id' => (int) $data['uom_id'],
        ]);

        return $row->refresh()->load(['ingredientItem.itemType', 'uom']);
    }

    public function removeLine(Item $item, RecipeItem $row): void
    {
        $recipe = $this->recipeService->getForItem($item);
        $this->assertScoped($recipe, $row);
        $row->delete();
    }

    /**
     * @param  list<array{item_id:int,quantity:numeric,uom_id:int}>  $ingredients
     */
    public function sync(Item $item, array $ingredients): Collection
    {
        $recipe = $this->recipeService->ensureForItem($item);

        return DB::transaction(function () use ($item, $recipe, $ingredients): Collection {
            $lines = [];
            foreach ($ingredients as $row) {
                $ingredientId = (string) $row['item_id'];
                $lines[$ingredientId] = [
                    'quantity' => number_format((float) $row['quantity'], 6, '.', ''),
                    'uom_id' => (int) $row['uom_id'],
                ];
            }

            foreach (array_keys($lines) as $ingredientId) {
                $ingredient = Item::query()->findOrFail($ingredientId);
                RecipeRules::assertValidIngredient($item, $ingredient);
                ItemUomValidation::assertUomAllowedForItem($ingredient, $lines[$ingredientId]['uom_id']);
            }

            RecipeItem::query()->where('recipe_id', $recipe->id)->delete();

            foreach ($lines as $ingredientId => $line) {
                RecipeItem::query()->create([
                    'recipe_id' => $recipe->id,
                    'item_id' => $ingredientId,
                    'quantity' => $line['quantity'],
                    'uom_id' => $line['uom_id'],
                ]);
            }

            return $this->listForRecipe($recipe);
        });
    }

    /**
     * Ingredients for production posting (quantities in line UOM; convert in production service later).
     *
     * @return Collection<int, RecipeItem>
     */
    public function ingredientsForProduction(Item $item): Collection
    {
        $recipe = $this->recipeService->getForItem($item);

        return $this->listForRecipe($recipe);
    }

    private function assertScoped(Recipe $recipe, RecipeItem $row): void
    {
        if ((int) $row->recipe_id !== (int) $recipe->id) {
            abort(404, 'Recipe line not found for this item.', ['X-Error-Code' => 'RECIPE_ITEM_SCOPE_MISMATCH']);
        }
    }
}
