<?php

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\DTOs\StockMovementResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Stock\Services\StockMovementQueryService;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\Stock\Support\StockMovementQuantityOnHand;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Collection;
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
            'item_id' => $request->string('item_id')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->stockMovementQueryService->paginate($filters, ListPagination::perPage($request, 50)),
            fn ($movement): array => StockMovementResponseData::fromModel($movement),
            'Stock movements fetched successfully.'
        );
    }

    public function show(int $stock_movement): JsonResponse
    {
        $movement = $this->stockMovementQueryService->find($stock_movement);
        $onHandByMovementId = StockMovementQuantityOnHand::mapForMovements(
            new Collection([$movement])
        );
        $onHand = $onHandByMovementId[$movement->id] ?? null;

        return ApiResponse::success(
            StockMovementResponseData::fromModel($movement, $onHand),
            'Stock movement fetched successfully.'
        );
    }

    public function storeAdjustment(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $data = StockMovementData::fromAdjustmentRequest($request, $userId !== null ? (string) $userId : null);

        $movement = $this->stockMovementService->post($data);

        $onHandByMovementId = StockMovementQuantityOnHand::mapForMovements(
            new Collection([$movement])
        );
        $onHand = $onHandByMovementId[$movement->id] ?? null;

        return ApiResponse::created(
            StockMovementResponseData::fromModel($movement, $onHand),
            'Stock adjustment posted successfully.'
        );
    }
}
