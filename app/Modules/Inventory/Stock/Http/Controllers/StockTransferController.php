<?php

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockTransferLineResponseData;
use App\Modules\Inventory\Stock\DTOs\StockTransferResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Stock\Http\Requests\SyncStockTransferLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateStockTransferRequest;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $stockTransferService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'from_warehouse_id' => $request->integer('from_warehouse_id') ?: null,
            'to_warehouse_id' => $request->integer('to_warehouse_id') ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'limit' => $request->integer('limit') ?: 100,
        ];

        return ApiResponse::success(
            StockTransferResponseData::collectionToArray($this->stockTransferService->list($filters)),
            'Stock transfers fetched successfully.'
        );
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $transfer = $this->stockTransferService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            StockTransferResponseData::fromModel($transfer),
            'Stock transfer created successfully.'
        );
    }

    public function show(StockTransfer $stockTransfer): JsonResponse
    {
        return ApiResponse::success(
            StockTransferResponseData::fromModel($this->stockTransferService->find($stockTransfer->id)),
            'Stock transfer fetched successfully.'
        );
    }

    public function update(UpdateStockTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        $transfer = $this->stockTransferService->updateHeader(
            $stockTransfer,
            $request->validated()
        );

        return ApiResponse::success(
            StockTransferResponseData::fromModel($transfer),
            'Stock transfer updated successfully.'
        );
    }

    public function destroy(StockTransfer $stockTransfer): JsonResponse
    {
        $this->stockTransferService->delete($stockTransfer);

        return ApiResponse::success(null, 'Stock transfer deleted successfully.');
    }

    public function syncLines(SyncStockTransferLinesRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        $lines = $this->stockTransferService->syncLines(
            $stockTransfer,
            $request->validated('lines')
        );

        return ApiResponse::success(
            StockTransferLineResponseData::collectionToArray($lines),
            'Stock transfer lines synced successfully.'
        );
    }

    public function post(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $userId = $request->user()?->id;
        $transfer = $this->stockTransferService->post(
            $stockTransfer,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            StockTransferResponseData::fromModel($transfer),
            'Stock transfer posted successfully.'
        );
    }

    public function cancel(StockTransfer $stockTransfer): JsonResponse
    {
        $transfer = $this->stockTransferService->cancel($stockTransfer);

        return ApiResponse::success(
            StockTransferResponseData::fromModel($transfer),
            'Stock transfer cancelled successfully.'
        );
    }
}
