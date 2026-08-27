<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockAdjustmentLineResponseData;
use App\Modules\Inventory\Stock\DTOs\StockAdjustmentResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Stock\Http\Requests\SyncStockAdjustmentLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateStockAdjustmentRequest;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Services\StockAdjustmentService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'reason_id' => $request->integer('reason_id') ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->stockAdjustmentService->list($filters, ListPagination::perPage($request, 50)),
            fn (StockAdjustment $document): array => StockAdjustmentResponseData::fromModel($document, false),
            'Stock adjustments fetched successfully.'
        );
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->stockAdjustmentService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            StockAdjustmentResponseData::fromModel($document),
            'Stock adjustment created successfully.'
        );
    }

    public function show(StockAdjustment $stock_adjustment): JsonResponse
    {
        return ApiResponse::success(
            StockAdjustmentResponseData::fromModel($this->stockAdjustmentService->find($stock_adjustment->id)),
            'Stock adjustment fetched successfully.'
        );
    }

    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stock_adjustment): JsonResponse
    {
        $document = $this->stockAdjustmentService->updateHeader(
            $stock_adjustment,
            $request->validated()
        );

        return ApiResponse::success(
            StockAdjustmentResponseData::fromModel($document),
            'Stock adjustment updated successfully.'
        );
    }

    public function destroy(StockAdjustment $stock_adjustment): JsonResponse
    {
        $this->stockAdjustmentService->delete($stock_adjustment);

        return ApiResponse::success(null, 'Stock adjustment deleted successfully.');
    }

    public function syncLines(SyncStockAdjustmentLinesRequest $request, StockAdjustment $stock_adjustment): JsonResponse
    {
        $lines = $this->stockAdjustmentService->syncLines(
            $stock_adjustment,
            $request->validated('lines')
        );

        return ApiResponse::success(
            StockAdjustmentLineResponseData::collectionToArray($lines),
            'Stock adjustment lines synced successfully.'
        );
    }

    public function post(Request $request, StockAdjustment $stock_adjustment): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->stockAdjustmentService->post(
            $stock_adjustment,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            StockAdjustmentResponseData::fromModel($document),
            'Stock adjustment posted successfully.'
        );
    }
}
