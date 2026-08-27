<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Item\Models\RecipeItem;
use App\Modules\Inventory\Item\Support\RecipeRules;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\ProductionStatus;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Inventory\Stock\Models\ProductionLine;
use App\Modules\Inventory\Stock\Support\InventoryCostingMath as Math;
use App\Modules\Inventory\Stock\Support\ProductionQuantity;
use App\Modules\Inventory\Stock\Support\ProductionRules;
use App\Modules\Inventory\Stock\Support\ProductionScale;
use App\Modules\Inventory\Stock\Support\StockAdjustmentQuantity;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function __construct(
        private readonly ProductionQueryService $productionQueryService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly InventoryLotService $inventoryLotService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   item_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, Production>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->productionQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): Production
    {
        $document = Production::query()
            ->with([
                'warehouse',
                'item.itemType',
                'recipe.uom',
                'itemUom.uom',
                'lot',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
                'lines.lot',
            ])
            ->withCount('lines')
            ->findOrFail($id);

        $this->warehouseService->assertVisibleById((int) $document->warehouse_id);

        return $document;
    }

    /**
     * @param  array{
     *   warehouse_id:int,
     *   item_id:string,
     *   quantity:numeric,
     *   production_date?:string,
     *   notes?:?string,
     *   lot_id?:int|null,
     *   lot_number?:string|null,
     *   expiry_date?:string|null,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): Production
    {
        $warehouseId = (int) $data['warehouse_id'];
        ProductionRules::assertWarehouse($warehouseId);

        $item = Item::query()->findOrFail((string) $data['item_id']);
        $recipe = ProductionRules::assertProducibleItem($item);
        $headerQty = $this->normalizeProduceQty($data['quantity'] ?? null);
        $itemUomId = ProductionQuantity::itemUomIdForCatalogUom($item, (int) $recipe->uom_id);
        $baseQuantity = StockAdjustmentQuantity::resolveBaseDelta($item, (float) $headerQty, $itemUomId);

        return DB::transaction(function () use ($data, $warehouseId, $item, $recipe, $headerQty, $itemUomId, $baseQuantity, $userId): Production {
            $document = Production::query()->create([
                'warehouse_id' => $warehouseId,
                'item_id' => $item->id,
                'recipe_id' => $recipe->id,
                'status' => ProductionStatus::Draft,
                'production_date' => $data['production_date'] ?? now()->toDateString(),
                'quantity' => $headerQty,
                'base_quantity' => $baseQuantity,
                'yield_quantity' => number_format((float) $recipe->yield_quantity, 6, '.', ''),
                'item_uom_id' => $itemUomId,
                'lot_id' => $this->resolveProduceLotId($item, $data),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $document->update(['prd_number' => $this->formatPrdNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($document, $recipe, $data['lines']);
            } else {
                $this->expandFromRecipe($document, $recipe);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  array{
     *   warehouse_id?:int,
     *   item_id?:string,
     *   quantity?:numeric,
     *   production_date?:string,
     *   notes?:?string,
     *   lot_id?:int|null,
     *   lot_number?:string|null,
     *   expiry_date?:string|null
     * }  $data
     */
    public function updateHeader(Production $document, array $data): Production
    {
        return DB::transaction(function () use ($document, $data): Production {
            $document = $this->lockDraft($document);

            $item = $document->item ?? Item::query()->findOrFail($document->item_id);
            $rebuild = false;

            $updates = [
                'production_date' => array_key_exists('production_date', $data)
                    ? (string) $data['production_date']
                    : $document->production_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $document->notes,
            ];

            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                $warehouseId = (int) $data['warehouse_id'];
                ProductionRules::assertWarehouse($warehouseId);
                $updates['warehouse_id'] = $warehouseId;
            }

            if (array_key_exists('item_id', $data) && $data['item_id'] !== null && (string) $data['item_id'] !== (string) $document->item_id) {
                $item = Item::query()->findOrFail((string) $data['item_id']);
                $recipe = ProductionRules::assertProducibleItem($item);
                $updates['item_id'] = $item->id;
                $updates['recipe_id'] = $recipe->id;
                $updates['yield_quantity'] = number_format((float) $recipe->yield_quantity, 6, '.', '');
                $updates['lot_id'] = null;
                $rebuild = true;
            } else {
                $recipe = $document->recipe ?? Recipe::query()->findOrFail($document->recipe_id);
            }

            $quantity = array_key_exists('quantity', $data) && $data['quantity'] !== null
                ? $this->normalizeProduceQty($data['quantity'])
                : number_format((float) $document->quantity, 6, '.', '');

            if ($quantity !== number_format((float) $document->quantity, 6, '.', '')) {
                $rebuild = true;
            }

            $itemUomId = ProductionQuantity::itemUomIdForCatalogUom($item, (int) $recipe->uom_id);
            $updates['quantity'] = $quantity;
            $updates['item_uom_id'] = $itemUomId;
            $updates['base_quantity'] = StockAdjustmentQuantity::resolveBaseDelta($item, (float) $quantity, $itemUomId);
            if ($rebuild) {
                $updates['yield_quantity'] = number_format((float) $recipe->yield_quantity, 6, '.', '');
            }

            if (array_key_exists('lot_id', $data) || array_key_exists('lot_number', $data) || array_key_exists('expiry_date', $data)) {
                $updates['lot_id'] = $this->resolveProduceLotId($item, $data);
            }

            $document->update($updates);

            if ($rebuild) {
                $preservedLots = $this->lineLotsByItemId($document);
                $this->expandFromRecipe($document->fresh() ?? $document, $recipe, $preservedLots);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(Production $document, array $lines): Collection
    {
        return DB::transaction(function () use ($document, $lines): Collection {
            $document = $this->lockDraft($document);
            $recipe = $document->recipe ?? Recipe::query()->with('recipeItems')->findOrFail($document->recipe_id);
            $this->replaceLines($document, $recipe, $lines);

            return ProductionLine::query()
                ->where('production_id', $document->id)
                ->with(['item', 'itemUom.uom', 'lot'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(Production $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = $this->lockDraft($document);
            $document->delete();
        });
    }

    public function post(Production $document, ?string $userId): Production
    {
        return DB::transaction(function () use ($document, $userId): Production {
            $locked = Production::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            ProductionRules::assertPostable($locked);
            ProductionRules::assertWarehouse((int) $locked->warehouse_id);

            $item = $locked->item ?? Item::query()->findOrFail($locked->item_id);
            $recipe = ProductionRules::assertProducibleItem($item);

            $lines = ProductionLine::query()
                ->where('production_id', $locked->id)
                ->with(['item'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertRecipeIngredientsPresent($recipe, $lines);

            if ($item->track_lots && ! $locked->lot_id) {
                abort(422, 'Enter a lot number when producing a lot-tracked item.', [
                    'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                ]);
            }

            $referenceNote = 'Production '.$locked->prd_number;
            $consumedValue = Math::money(0);

            foreach ($lines as $line) {
                $ingredient = $line->item ?? Item::query()->findOrFail($line->item_id);
                ProductionRules::assertStockableItem($ingredient);
                RecipeRules::assertValidIngredient($item, $ingredient);

                if ($ingredient->track_lots && ! $line->lot_id) {
                    abort(422, 'Select a lot when issuing a lot-tracked ingredient.', [
                        'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                    ]);
                }

                $itemUomId = $line->item_uom_id ? (int) $line->item_uom_id : null;
                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;
                $outboundQty = bcmul((string) $line->base_quantity, '-1', 6);

                $movement = $this->stockMovementService->post(StockMovementData::forProduction(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->warehouse_id,
                    quantityDelta: $outboundQty,
                    type: StockMovementType::ProductionOut,
                    unitCost: null,
                    itemUomId: $itemUomId,
                    notes: $lineNote,
                    userId: $userId,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    productionId: (string) $locked->id,
                ));

                $consumedValue = bcadd(
                    $consumedValue,
                    bccomp((string) $movement->value_delta, '0', Math::MONEY_SCALE) < 0
                        ? bcmul((string) $movement->value_delta, '-1', Math::MONEY_SCALE)
                        : Math::money($movement->value_delta),
                    Math::MONEY_SCALE
                );
            }

            $producedBase = (string) $locked->base_quantity;
            $unitCost = bccomp($producedBase, '0', 6) > 0
                ? bcdiv($consumedValue, $producedBase, Math::MONEY_SCALE)
                : null;

            $this->stockMovementService->post(StockMovementData::forProduction(
                itemId: (string) $locked->item_id,
                warehouseId: (int) $locked->warehouse_id,
                quantityDelta: $producedBase,
                type: StockMovementType::ProductionIn,
                unitCost: $unitCost,
                itemUomId: $locked->item_uom_id ? (int) $locked->item_uom_id : null,
                notes: $referenceNote,
                userId: $userId,
                lotId: $locked->lot_id ? (int) $locked->lot_id : null,
                productionId: (string) $locked->id,
            ));

            $locked->update([
                'status' => ProductionStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  array<string, int|null>  $preservedLots
     */
    private function expandFromRecipe(Production $document, Recipe $recipe, array $preservedLots = []): void
    {
        $recipe->loadMissing('recipeItems');
        $factor = ProductionScale::factor(
            (string) $document->quantity,
            (string) $document->yield_quantity,
        );

        $lines = [];
        foreach ($recipe->recipeItems as $recipeItem) {
            $itemId = (string) $recipeItem->item_id;
            $theoretical = ProductionScale::apply((string) $recipeItem->quantity, $factor);
            $lines[] = [
                'item_id' => $itemId,
                'recipe_item_id' => $recipeItem->id,
                'quantity' => $theoretical,
                'theoretical_quantity' => $theoretical,
                'lot_id' => $preservedLots[$itemId] ?? null,
            ];
        }

        $this->replaceLines($document, $recipe, $lines, bindUomFromRecipe: true);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Production $document, Recipe $recipe, array $lines, bool $bindUomFromRecipe = false): void
    {
        $recipe->loadMissing('recipeItems');
        $recipeItems = $recipe->recipeItems->keyBy(fn (RecipeItem $row): string => (string) $row->item_id);
        $factor = ProductionScale::factor(
            (string) $document->quantity,
            (string) $document->yield_quantity,
        );
        $seenRecipeItems = [];
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = isset($row['item_id']) ? (string) $row['item_id'] : '';
            if ($itemId === '') {
                abort(422, 'Select an item for each production line.', [
                    'X-Error-Code' => 'PRODUCTION_ITEM_REQUIRED',
                ]);
            }

            $recipeItem = $recipeItems->get($itemId);
            if (! $recipeItem) {
                abort(422, 'Ingredient is not on this recipe.', [
                    'X-Error-Code' => 'PRODUCTION_INGREDIENT_UNKNOWN',
                ]);
            }

            $item = Item::query()->findOrFail($itemId);
            ProductionRules::assertStockableItem($item);
            RecipeRules::assertValidIngredient(
                $document->item ?? Item::query()->findOrFail($document->item_id),
                $item,
            );

            $quantity = (float) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                abort(422, 'Ingredient quantity must be greater than zero.', [
                    'X-Error-Code' => 'PRODUCTION_LINE_INVALID_QUANTITY',
                ]);
            }

            $itemUomId = $bindUomFromRecipe || ! isset($row['item_uom_id']) || $row['item_uom_id'] === null || $row['item_uom_id'] === ''
                ? ProductionQuantity::itemUomIdForCatalogUom($item, (int) $recipeItem->uom_id)
                : (int) $row['item_uom_id'];

            $baseQuantity = StockAdjustmentQuantity::resolveBaseDelta($item, $quantity, $itemUomId);
            $lotId = $this->resolveLineLotId($item, $row);
            $lineKey = $itemId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed.', [
                    'X-Error-Code' => 'PRODUCTION_DUPLICATE_LINE',
                ]);
            }

            $theoretical = isset($row['theoretical_quantity']) && $row['theoretical_quantity'] !== null && $row['theoretical_quantity'] !== ''
                ? number_format((float) $row['theoretical_quantity'], 6, '.', '')
                : ProductionScale::apply((string) $recipeItem->quantity, $factor);

            $seenRecipeItems[$itemId] = true;
            $normalized[$lineKey] = [
                'item_id' => $itemId,
                'recipe_item_id' => $recipeItem->id,
                'quantity' => number_format($quantity, 6, '.', ''),
                'base_quantity' => $baseQuantity,
                'theoretical_quantity' => $theoretical,
                'item_uom_id' => $itemUomId,
                'lot_id' => $lotId,
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        foreach ($recipeItems->keys() as $recipeItemId) {
            if (! isset($seenRecipeItems[(string) $recipeItemId])) {
                abort(422, 'Every recipe ingredient must appear on the production.', [
                    'X-Error-Code' => 'PRODUCTION_INGREDIENT_MISSING',
                ]);
            }
        }

        ProductionLine::query()->where('production_id', $document->id)->delete();

        foreach ($normalized as $line) {
            ProductionLine::query()->create([
                'production_id' => $document->id,
                ...$line,
            ]);
        }
    }

    /**
     * @param  Collection<int, ProductionLine>  $lines
     */
    private function assertRecipeIngredientsPresent(Recipe $recipe, Collection $lines): void
    {
        $recipe->loadMissing('recipeItems');
        $present = $lines->pluck('item_id')->map(fn ($id): string => (string) $id)->unique();

        foreach ($recipe->recipeItems as $recipeItem) {
            if (! $present->contains((string) $recipeItem->item_id)) {
                abort(422, 'Every recipe ingredient must appear on the production.', [
                    'X-Error-Code' => 'PRODUCTION_INGREDIENT_MISSING',
                ]);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function lineLotsByItemId(Production $document): array
    {
        $lots = [];
        $rows = ProductionLine::query()
            ->where('production_id', $document->id)
            ->whereNotNull('lot_id')
            ->get(['item_id', 'lot_id']);

        foreach ($rows as $row) {
            $lots[(string) $row->item_id] = (int) $row->lot_id;
        }

        return $lots;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveProduceLotId(Item $item, array $data): ?int
    {
        return $this->resolveLotId($item, $data, inbound: true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLineLotId(Item $item, array $row): ?int
    {
        return $this->resolveLotId($item, $row, inbound: false);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLotId(Item $item, array $row, bool $inbound): ?int
    {
        $lotIdInput = isset($row['lot_id']) && $row['lot_id'] !== '' && $row['lot_id'] !== null
            ? (int) $row['lot_id']
            : null;
        if ($lotIdInput === 0) {
            $lotIdInput = null;
        }
        $lotNumberInput = isset($row['lot_number']) ? (string) $row['lot_number'] : null;
        $hasLotInput = $lotIdInput !== null
            || InventoryLotService::normalizeLotNumber($lotNumberInput) !== null;

        if (! $hasLotInput) {
            return null;
        }

        $lot = $this->inventoryLotService->resolve(
            $item,
            $lotIdInput,
            $lotNumberInput,
            isset($row['expiry_date']) ? (string) $row['expiry_date'] : null,
            inbound: $inbound,
        );

        return $lot?->id;
    }

    private function lockDraft(Production $document): Production
    {
        $locked = Production::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        ProductionRules::assertDraft($locked);

        return $locked;
    }

    private function formatPrdNumber(): string
    {
        $seq = Production::query()->count();

        return 'PRD-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeProduceQty(mixed $value): string
    {
        $qty = number_format((float) $value, 6, '.', '');
        if (bccomp($qty, '0', 6) <= 0) {
            abort(422, 'Production quantity must be greater than zero.', [
                'X-Error-Code' => 'PRODUCTION_QTY_INVALID',
            ]);
        }

        return $qty;
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
