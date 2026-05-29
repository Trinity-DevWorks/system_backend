<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\RecipeItemResponseData;
use App\Modules\Inventory\Item\Http\Requests\StoreRecipeItemRequest;
use App\Modules\Inventory\Item\Http\Requests\SyncRecipeItemsRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateRecipeItemRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\RecipeItem;
use App\Modules\Inventory\Item\Services\RecipeItemService;
use Illuminate\Http\JsonResponse;

class RecipeItemController extends Controller
{
    public function __construct(
        private readonly RecipeItemService $recipeItemService
    ) {}

    public function index(Item $item): JsonResponse
    {
        return ApiResponse::success(
            RecipeItemResponseData::collectionToArray($this->recipeItemService->listForItem($item)),
            'Recipe ingredients fetched successfully.'
        );
    }

    public function store(StoreRecipeItemRequest $request, Item $item): JsonResponse
    {
        $row = $this->recipeItemService->addIngredient($item, $request->validated());

        return ApiResponse::created(
            RecipeItemResponseData::fromModel($row),
            'Recipe ingredient added successfully.'
        );
    }

    public function sync(SyncRecipeItemsRequest $request, Item $item): JsonResponse
    {
        $rows = $this->recipeItemService->sync($item, $request->validated('ingredients'));

        return ApiResponse::success(
            RecipeItemResponseData::collectionToArray($rows),
            'Recipe ingredients synced successfully.'
        );
    }

    public function update(UpdateRecipeItemRequest $request, Item $item, RecipeItem $recipeItem): JsonResponse
    {
        $row = $this->recipeItemService->updateLine($item, $recipeItem, $request->validated());

        return ApiResponse::success(
            RecipeItemResponseData::fromModel($row),
            'Recipe ingredient updated successfully.'
        );
    }

    public function destroy(Item $item, RecipeItem $recipeItem): JsonResponse
    {
        $this->recipeItemService->removeLine($item, $recipeItem);

        return ApiResponse::success(null, 'Recipe ingredient removed successfully.');
    }
}
