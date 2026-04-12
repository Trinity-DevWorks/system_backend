<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\ItemUomPivotResponseData;
use App\Modules\Inventory\Item\Http\Requests\AttachItemUomRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemUomRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\ItemUomService;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Http\JsonResponse;

class ItemUomController extends Controller
{
    public function __construct(
        private readonly ItemUomService $itemUomService
    ) {}

    public function index(Item $item): JsonResponse
    {
        $rows = $this->itemUomService->listForItem($item);
        $data = $rows->map(fn ($p): array => ItemUomPivotResponseData::fromPivot($p))->values()->all();

        return ApiResponse::success($data, 'Item units of measurement fetched successfully.');
    }

    public function store(AttachItemUomRequest $request, Item $item): JsonResponse
    {
        $pivot = $this->itemUomService->attach($item, $request->validated());

        return ApiResponse::created(
            ItemUomPivotResponseData::fromPivot($pivot),
            'Unit of measurement attached successfully.'
        );
    }

    public function update(UpdateItemUomRequest $request, Item $item, UnitOfMeasurement $unitOfMeasurement): JsonResponse
    {
        $pivot = $this->itemUomService->updatePivot($item, $unitOfMeasurement, $request->validated());

        return ApiResponse::success(
            ItemUomPivotResponseData::fromPivot($pivot),
            'Item unit of measurement updated successfully.'
        );
    }

    public function destroy(Item $item, UnitOfMeasurement $unitOfMeasurement): JsonResponse
    {
        $this->itemUomService->detach($item, $unitOfMeasurement);

        return ApiResponse::success(null, 'Unit of measurement detached successfully.');
    }
}
