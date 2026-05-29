<?php

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\DTOs\StockMovementResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockMovementQueryService;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly StockMovementQueryService $stockMovementQueryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'item_id' => $request->integer('item_id') ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'limit' => $request->integer('limit') ?: 100,
        ];

        return ApiResponse::success(
            StockMovementResponseData::collectionToArray($this->stockMovementQueryService->list($filters)),
            'Stock movements fetched successfully.'
        );
    }

    public function storeAdjustment(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $data = StockMovementData::fromAdjustmentRequest($request, $userId !== null ? (int) $userId : null);

        $movement = $this->stockMovementService->post($data);

        $onHand = StockBalance::query()
            ->where('item_id', $movement->item_id)
            ->where('warehouse_id', $movement->warehouse_id)
            ->value('quantity');

        return ApiResponse::created(
            StockMovementResponseData::fromModel($movement, $onHand !== null ? (string) $onHand : null),
            'Stock adjustment posted successfully.'
        );
    }
}
