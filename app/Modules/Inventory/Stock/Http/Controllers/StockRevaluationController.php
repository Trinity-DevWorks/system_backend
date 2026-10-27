<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockRevaluationLineResponseData;
use App\Modules\Inventory\Stock\DTOs\StockRevaluationResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockRevaluationRequest;
use App\Modules\Inventory\Stock\Http\Requests\SyncStockRevaluationLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateStockRevaluationRequest;
use App\Modules\Inventory\Stock\Models\StockRevaluation;
use App\Modules\Inventory\Stock\Services\StockRevaluationService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockRevaluationController extends Controller
{
    public function __construct(
        private readonly StockRevaluationService $stockRevaluationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->stockRevaluationService->list($filters, ListPagination::perPage($request, 50)),
            fn (StockRevaluation $document): array => StockRevaluationResponseData::fromModel($document, false),
            'Stock revaluations fetched successfully.'
        );
    }

    public function balanceHint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
        ]);

        $hint = $this->stockRevaluationService->balanceHint(
            (int) $validated['warehouse_id'],
            (string) $validated['item_id'],
            isset($validated['lot_id']) ? (int) $validated['lot_id'] : null,
        );

        return ApiResponse::success($hint, 'Balance hint fetched successfully.');
    }

    public function store(StoreStockRevaluationRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->stockRevaluationService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            StockRevaluationResponseData::fromModel($document),
            'Stock revaluation created successfully.'
        );
    }

    public function show(StockRevaluation $stock_revaluation): JsonResponse
    {
        return ApiResponse::success(
            StockRevaluationResponseData::fromModel($this->stockRevaluationService->find($stock_revaluation->id)),
            'Stock revaluation fetched successfully.'
        );
    }

    public function update(UpdateStockRevaluationRequest $request, StockRevaluation $stock_revaluation): JsonResponse
    {
        $document = $this->stockRevaluationService->updateHeader(
            $stock_revaluation,
            $request->validated()
        );

        return ApiResponse::success(
            StockRevaluationResponseData::fromModel($document),
            'Stock revaluation updated successfully.'
        );
    }

    public function destroy(StockRevaluation $stock_revaluation): JsonResponse
    {
        $this->stockRevaluationService->delete($stock_revaluation);

        return ApiResponse::success(null, 'Stock revaluation deleted successfully.');
    }

    public function syncLines(SyncStockRevaluationLinesRequest $request, StockRevaluation $stock_revaluation): JsonResponse
    {
        $lines = $this->stockRevaluationService->syncLines(
            $stock_revaluation,
            $request->validated('lines')
        );

        return ApiResponse::success(
            StockRevaluationLineResponseData::collectionToArray($lines),
            'Stock revaluation lines synced successfully.'
        );
    }

    public function post(Request $request, StockRevaluation $stock_revaluation): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->stockRevaluationService->post(
            $stock_revaluation,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            StockRevaluationResponseData::fromModel($document),
            'Stock revaluation posted successfully.'
        );
    }

    public function reverse(Request $request, StockRevaluation $stock_revaluation): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->stockRevaluationService->reverse(
            $stock_revaluation,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            StockRevaluationResponseData::fromModel($document),
            'Stock revaluation reversed successfully.'
        );
    }
}
