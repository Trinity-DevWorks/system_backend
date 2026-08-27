<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\OpeningStockLineResponseData;
use App\Modules\Inventory\Stock\DTOs\OpeningStockResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreOpeningStockRequest;
use App\Modules\Inventory\Stock\Http\Requests\SyncOpeningStockLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateOpeningStockRequest;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Inventory\Stock\Services\OpeningStockService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpeningStockController extends Controller
{
    public function __construct(
        private readonly OpeningStockService $openingStockService,
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
            $this->openingStockService->list($filters, ListPagination::perPage($request, 50)),
            fn (OpeningStock $document): array => OpeningStockResponseData::fromModel($document, false),
            'Opening stock documents fetched successfully.'
        );
    }

    public function store(StoreOpeningStockRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->openingStockService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            OpeningStockResponseData::fromModel($document),
            'Opening stock document created successfully.'
        );
    }

    public function show(OpeningStock $opening_stock): JsonResponse
    {
        return ApiResponse::success(
            OpeningStockResponseData::fromModel($this->openingStockService->find($opening_stock->id)),
            'Opening stock document fetched successfully.'
        );
    }

    public function update(UpdateOpeningStockRequest $request, OpeningStock $opening_stock): JsonResponse
    {
        $document = $this->openingStockService->updateHeader(
            $opening_stock,
            $request->validated()
        );

        return ApiResponse::success(
            OpeningStockResponseData::fromModel($document),
            'Opening stock document updated successfully.'
        );
    }

    public function destroy(OpeningStock $opening_stock): JsonResponse
    {
        $this->openingStockService->delete($opening_stock);

        return ApiResponse::success(null, 'Opening stock document deleted successfully.');
    }

    public function syncLines(SyncOpeningStockLinesRequest $request, OpeningStock $opening_stock): JsonResponse
    {
        $lines = $this->openingStockService->syncLines(
            $opening_stock,
            $request->validated('lines')
        );

        return ApiResponse::success(
            OpeningStockLineResponseData::collectionToArray($lines),
            'Opening stock lines synced successfully.'
        );
    }

    public function post(Request $request, OpeningStock $opening_stock): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->openingStockService->post(
            $opening_stock,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            OpeningStockResponseData::fromModel($document),
            'Opening stock document posted successfully.'
        );
    }
}
