<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\RecipeResponseData;
use App\Modules\Inventory\Item\Http\Requests\UpsertRecipeRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\RecipeService;
use Illuminate\Http\JsonResponse;

class RecipeController extends Controller
{
    public function __construct(
        private readonly RecipeService $recipeService
    ) {}

    public function show(Item $item): JsonResponse
    {
        $recipe = $this->recipeService->getForItem($item);

        return ApiResponse::success(
            RecipeResponseData::fromModel($recipe),
            'Recipe fetched successfully.'
        );
    }

    public function upsert(UpsertRecipeRequest $request, Item $item): JsonResponse
    {
        $recipe = $this->recipeService->upsertForItem($item, $request->validated());

        return ApiResponse::success(
            RecipeResponseData::fromModel($recipe),
            'Recipe saved successfully.'
        );
    }
}
