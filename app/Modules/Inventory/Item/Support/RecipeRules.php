<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Item\Models\RecipeItem;

final class RecipeRules
{
    public const PRODUCE_TYPE_CODE = 'PRODUCE';

    /** @var list<string> */
    private const ALLOWED_INGREDIENT_TYPE_CODES = [
        'INGREDIENT',
        'INVENTORY',
        'PRODUCE',
    ];

    /** @var list<string> */
    private const DISALLOWED_INGREDIENT_TYPE_CODES = [
        'BUNDLE',
        'SERVICE',
        'PLU',
        'NON_INVENTORY',
    ];

    public static function assertIsProducedItem(Item $item): void
    {
        $item->loadMissing('itemType:id,code');

        if (! $item->itemType || strtoupper($item->itemType->code) !== self::PRODUCE_TYPE_CODE) {
            abort(422, 'Item must be of type produce.', ['X-Error-Code' => 'ITEM_NOT_PRODUCE_TYPE']);
        }

        if (! $item->is_active) {
            abort(422, 'Produced item must be active.', ['X-Error-Code' => 'RECIPE_ITEM_INACTIVE']);
        }

        if (! $item->track_inventory) {
            abort(422, 'Produced item must track inventory.', ['X-Error-Code' => 'RECIPE_ITEM_NO_STOCK_TRACKING']);
        }
    }

    public static function assertValidIngredient(Item $producedItem, Item $ingredient): void
    {
        if ((int) $producedItem->id === (int) $ingredient->id) {
            abort(422, 'A recipe cannot use the produced item as an ingredient.', ['X-Error-Code' => 'RECIPE_SELF_REFERENCE']);
        }

        if (! $ingredient->is_active) {
            abort(422, 'Recipe ingredient must be an active item.', ['X-Error-Code' => 'RECIPE_INGREDIENT_INACTIVE']);
        }

        $ingredient->loadMissing('itemType:id,code');
        $code = strtoupper((string) ($ingredient->itemType?->code ?? ''));

        if (in_array($code, self::DISALLOWED_INGREDIENT_TYPE_CODES, true)) {
            abort(422, 'This item type cannot be used as a recipe ingredient.', ['X-Error-Code' => 'RECIPE_INGREDIENT_TYPE_NOT_ALLOWED']);
        }

        if ($code !== '' && ! in_array($code, self::ALLOWED_INGREDIENT_TYPE_CODES, true)) {
            abort(422, 'This item type cannot be used as a recipe ingredient.', ['X-Error-Code' => 'RECIPE_INGREDIENT_TYPE_NOT_ALLOWED']);
        }

        if (! $ingredient->track_inventory) {
            abort(422, 'Recipe ingredients must track inventory.', ['X-Error-Code' => 'RECIPE_INGREDIENT_NO_STOCK_TRACKING']);
        }

        if (self::recipeContainsItem($ingredient->id, $producedItem->id)) {
            abort(422, 'This would create a circular recipe reference.', ['X-Error-Code' => 'RECIPE_CIRCULAR_REFERENCE']);
        }
    }

    /**
     * True if $rootItemId has a recipe that eventually consumes $targetItemId as an ingredient.
     */
    private static function recipeContainsItem(int $rootItemId, int $targetItemId): bool
    {
        $visited = [];
        $stack = [$rootItemId];

        while ($stack !== []) {
            $currentId = array_pop($stack);
            if (isset($visited[$currentId])) {
                continue;
            }
            $visited[$currentId] = true;

            $recipe = Recipe::query()->where('item_id', $currentId)->first();
            if (! $recipe) {
                continue;
            }

            $ingredientIds = RecipeItem::query()
                ->where('recipe_id', $recipe->id)
                ->pluck('item_id')
                ->all();

            foreach ($ingredientIds as $ingredientId) {
                $ingredientId = (int) $ingredientId;
                if ($ingredientId === $targetItemId) {
                    return true;
                }
                $stack[] = $ingredientId;
            }
        }

        return false;
    }
}
