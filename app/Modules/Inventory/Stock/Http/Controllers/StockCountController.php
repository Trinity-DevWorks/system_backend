<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockCountLineResponseData;
use App\Modules\Inventory\Stock\DTOs\StockCountResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockCountRequest;
use App\Modules\Inventory\Stock\Http\Requests\SyncStockCountLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateStockCountRequest;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Services\StockCountService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockCountController extends Controller
{
    public function __construct(
        private readonly StockCountService $stockCountService,
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
            $this->stockCountService->list($filters, ListPagination::perPage($request, 50)),
            fn (StockCount $document): array => StockCountResponseData::fromModel($document, false),
            'Stock counts fetched successfully.'
        );
    }

    public function store(StoreStockCountRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->stockCountService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            StockCountResponseData::fromModel($document),
            'Stock count created successfully.'
        );
    }

    public function show(StockCount $stock_count): JsonResponse
    {
        return ApiResponse::success(
            StockCountResponseData::fromModel($this->stockCountService->find($stock_count->id)),
            'Stock count fetched successfully.'
        );
    }

    public function update(UpdateStockCountRequest $request, StockCount $stock_count): JsonResponse
    {
        $document = $this->stockCountService->updateHeader(
            $stock_count,
            $request->validated()
        );

        return ApiResponse::success(
            StockCountResponseData::fromModel($document),
            'Stock count updated successfully.'
        );
    }

    public function destroy(StockCount $stock_count): JsonResponse
    {
        $this->stockCountService->delete($stock_count);

        return ApiResponse::success(null, 'Stock count deleted successfully.');
    }

    public function syncLines(SyncStockCountLinesRequest $request, StockCount $stock_count): JsonResponse
    {
        $lines = $this->stockCountService->syncLines(
            $stock_count,
            $request->validated('lines')
        );

        return ApiResponse::success(
            StockCountLineResponseData::collectionToArray($lines),
            'Stock count lines synced successfully.'
        );
    }

    public function loadBalances(StockCount $stock_count): JsonResponse
    {
        return ApiResponse::success(
            StockCountResponseData::fromModel($this->stockCountService->loadBalances($stock_count)),
            'Stock count lines loaded from on-hand balances.'
        );
    }

    public function post(Request $request, StockCount $stock_count): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->stockCountService->post(
            $stock_count,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            StockCountResponseData::fromModel($document),
            'Stock count posted successfully.'
        );
    }
}
