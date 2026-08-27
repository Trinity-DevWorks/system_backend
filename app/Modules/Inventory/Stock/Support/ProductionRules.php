<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Item\Support\RecipeRules;
use App\Modules\Inventory\Stock\Enums\ProductionStatus;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

final class ProductionRules
{
    public static function assertDraft(Production $document): void
    {
        if ($document->status !== ProductionStatus::Draft) {
            abort(422, 'Only draft productions can be modified.', [
                'X-Error-Code' => 'PRODUCTION_NOT_DRAFT',
            ]);
        }
    }

    public static function assertPostable(Production $document): void
    {
        self::assertDraft($document);

        if ($document->lines()->count() === 0) {
            abort(422, 'Cannot post a production without ingredient lines.', [
                'X-Error-Code' => 'PRODUCTION_NO_LINES',
            ]);
        }
    }

    public static function assertWarehouse(int $warehouseId): void
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            abort(422, 'Cannot move stock in an inactive warehouse.', [
                'X-Error-Code' => 'STOCK_WAREHOUSE_INACTIVE',
            ]);
        }

        app(WarehouseService::class)->assertVisible($warehouse);
    }

    public static function assertProducibleItem(Item $item): Recipe
    {
        RecipeRules::assertIsProducedItem($item);

        $recipe = Recipe::query()
            ->where('item_id', $item->id)
            ->with(['recipeItems'])
            ->first();

        if (! $recipe) {
            abort(422, 'This item has no recipe.', [
                'X-Error-Code' => 'PRODUCTION_RECIPE_REQUIRED',
            ]);
        }

        if ($recipe->recipeItems->isEmpty()) {
            abort(422, 'Add ingredients to the recipe before producing.', [
                'X-Error-Code' => 'PRODUCTION_NO_INGREDIENTS',
            ]);
        }

        return $recipe;
    }

    public static function assertStockableItem(Item $item): void
    {
        if (! $item->is_active) {
            abort(422, 'Cannot move stock for an inactive item.', [
                'X-Error-Code' => 'STOCK_ITEM_INACTIVE',
            ]);
        }

        if (! $item->track_inventory) {
            abort(422, 'This item does not track inventory.', [
                'X-Error-Code' => 'STOCK_ITEM_NOT_TRACKED',
            ]);
        }
    }
}
