<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\DTOs\ItemResponseData;
use App\Modules\Inventory\Item\Http\Requests\StoreItemRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\ItemService;
use Illuminate\Http\JsonResponse;

class ItemController extends Controller
{
    public function __construct(
        private readonly ItemService $itemService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ItemResponseData::collectionToArray($this->itemService->list()),
            'Items fetched successfully.'
        );
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->itemService->create(ItemData::fromStoreRequest($request));

        return ApiResponse::created(
            ItemResponseData::fromModel($item)->toArray(),
            'Item created successfully.'
        );
    }

    public function show(Item $item): JsonResponse
    {
        $item->load(['baseUom', 'purchaseUom', 'salesUom']);

        return ApiResponse::success(
            ItemResponseData::fromModel($item)->toArray(),
            'Item fetched successfully.'
        );
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $updated = $this->itemService->update($item, ItemData::fromUpdateRequest($request));

        return ApiResponse::success(
            ItemResponseData::fromModel($updated)->toArray(),
            'Item updated successfully.'
        );
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->itemService->delete($item);

        return ApiResponse::success(null, 'Item deleted successfully.');
    }
}
