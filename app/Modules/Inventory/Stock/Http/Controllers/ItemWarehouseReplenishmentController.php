<?php

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\ItemWarehouseReplenishmentResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreItemWarehouseReplenishmentRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateItemWarehouseReplenishmentRequest;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Services\ItemWarehouseReplenishmentService;
use Illuminate\Http\JsonResponse;

class ItemWarehouseReplenishmentController extends Controller
{
    public function __construct(
        private readonly ItemWarehouseReplenishmentService $replenishmentService
    ) {}

    public function index(Item $item): JsonResponse
    {
        return ApiResponse::success(
            ItemWarehouseReplenishmentResponseData::collectionToArray(
                $this->replenishmentService->listForItem($item),
            ),
            'Item warehouse replenishment rules fetched successfully.'
        );
    }

    public function store(StoreItemWarehouseReplenishmentRequest $request, Item $item): JsonResponse
    {
        $row = $this->replenishmentService->create($item, $request->validated());

        return ApiResponse::created(
            ItemWarehouseReplenishmentResponseData::fromModel($row),
            'Item warehouse replenishment rule created successfully.'
        );
    }

    public function update(
        UpdateItemWarehouseReplenishmentRequest $request,
        Item $item,
        ItemWarehouseReplenishment $itemWarehouseReplenishment,
    ): JsonResponse {
        $row = $this->replenishmentService->update(
            $item,
            $itemWarehouseReplenishment,
            $request->validated(),
        );

        return ApiResponse::success(
            ItemWarehouseReplenishmentResponseData::fromModel($row),
            'Item warehouse replenishment rule updated successfully.'
        );
    }

    public function destroy(Item $item, ItemWarehouseReplenishment $itemWarehouseReplenishment): JsonResponse
    {
        $this->replenishmentService->delete($item, $itemWarehouseReplenishment);

        return ApiResponse::success(null, 'Item warehouse replenishment rule deleted successfully.');
    }
}
