<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\ItemUomResponseData;
use App\Modules\Inventory\Item\Http\Requests\StoreItemUomRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemUomRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Services\ItemUomService;
use Illuminate\Http\JsonResponse;

class ItemUomController extends Controller
{
    public function __construct(
        private readonly ItemUomService $itemUomService
    ) {}

    public function index(Item $item): JsonResponse
    {
        return ApiResponse::success(
            ItemUomResponseData::collectionToArray($this->itemUomService->listForItem($item)),
            'Item units of measurement fetched successfully.'
        );
    }

    public function store(StoreItemUomRequest $request, Item $item): JsonResponse
    {
        $row = $this->itemUomService->create($item, $request->validated());

        return ApiResponse::created(
            ItemUomResponseData::fromModel($row),
            'Item unit of measurement created successfully.'
        );
    }

    public function update(UpdateItemUomRequest $request, Item $item, ItemUom $itemUom): JsonResponse
    {
        $row = $this->itemUomService->update($item, $itemUom, $request->validated());

        return ApiResponse::success(
            ItemUomResponseData::fromModel($row),
            'Item unit of measurement updated successfully.'
        );
    }

    public function destroy(Item $item, ItemUom $itemUom): JsonResponse
    {
        $this->itemUomService->delete($item, $itemUom);

        return ApiResponse::success(null, 'Item unit of measurement deleted successfully.');
    }
}
