<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Stock\Support\ProductionQuantity;
use App\Modules\Inventory\Stock\Support\StockAdjustmentQuantity;
use App\Support\Decimal;

/**
 * "Made on sale" items (PRODUCE with a recipe, `make_on_sale` on): selling one takes its recipe's ingredients
 * out of stock instead of the item itself. Each ingredient: recipe qty × sold qty ÷ recipe yield, all in base units.
 */
final class RecipeConsumption
{
    /** Text in movement notes ("SI 12 recipe CODE") that ties ingredient movements to the item sold. */
    public const TAG = 'recipe';

    public static function isMadeOnSale(Item $item): bool
    {
        if (! (bool) $item->make_on_sale) {
            return false;
        }
        $item->loadMissing('itemType:id,code');

        return $item->itemType !== null && strtoupper((string) $item->itemType->code) === 'PRODUCE';
    }

    /**
     * Ingredients used by `$baseQuantity` (the sold item's base unit) of a made-on-sale item.
     *
     * @return list<array{item: Item, base_quantity: string}>
     */
    public static function ingredients(Item $item, string $baseQuantity, string $errorPrefix): array
    {
        $recipe = Recipe::query()->with('recipeItems.ingredientItem')->where('item_id', $item->id)->first();
        if ($recipe === null || $recipe->recipeItems->isEmpty() || ! Decimal::isPositive((string) $recipe->yield_quantity)) {
            abort(422, sprintf('"%s" is made on sale but has no recipe.', $item->name), ['X-Error-Code' => $errorPrefix.'_RECIPE_MISSING']);
        }

        // The recipe's yield in the sold item's base unit.
        $yieldBase = Decimal::abs(StockAdjustmentQuantity::resolveBaseDelta(
            $item,
            (string) $recipe->yield_quantity,
            ProductionQuantity::itemUomIdForCatalogUom($item, (int) $recipe->uom_id),
        ));
        $factor = Decimal::div(Decimal::abs($baseQuantity), $yieldBase, 12);

        $out = [];
        foreach ($recipe->recipeItems as $row) {
            $ingredient = $row->ingredientItem;
            if (! $ingredient instanceof Item || ! $ingredient->track_inventory) {
                continue;
            }
            if ($ingredient->track_lots || $ingredient->track_serials) {
                abort(422, sprintf('"%s" is lot or serial tracked and cannot be used up when "%s" is sold. Use a production instead.', $ingredient->name, $item->name), [
                    'X-Error-Code' => $errorPrefix.'_RECIPE_TRACKED_INGREDIENT',
                ]);
            }
            $inRecipeUnit = Decimal::round(Decimal::mul((string) $row->quantity, $factor), 6);
            if (! Decimal::isPositive($inRecipeUnit)) {
                continue;
            }
            $out[] = [
                'item' => $ingredient,
                'base_quantity' => Decimal::abs(StockAdjustmentQuantity::resolveBaseDelta(
                    $ingredient,
                    $inRecipeUnit,
                    ProductionQuantity::itemUomIdForCatalogUom($ingredient, (int) $row->uom_id),
                )),
            ];
        }

        return $out;
    }
}
