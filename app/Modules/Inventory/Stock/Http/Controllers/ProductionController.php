<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\ProductionLineResponseData;
use App\Modules\Inventory\Stock\DTOs\ProductionResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreProductionRequest;
use App\Modules\Inventory\Stock\Http\Requests\SyncProductionLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateProductionRequest;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Inventory\Stock\Services\ProductionService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(
        private readonly ProductionService $productionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'item_id' => $request->string('item_id')->toString() ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->productionService->list($filters, ListPagination::perPage($request, 50)),
            fn (Production $document): array => ProductionResponseData::fromModel($document, false),
            'Productions fetched successfully.'
        );
    }

    public function store(StoreProductionRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->productionService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            ProductionResponseData::fromModel($document),
            'Production created successfully.'
        );
    }

    public function show(Production $production): JsonResponse
    {
        return ApiResponse::success(
            ProductionResponseData::fromModel($this->productionService->find($production->id)),
            'Production fetched successfully.'
        );
    }

    public function update(UpdateProductionRequest $request, Production $production): JsonResponse
    {
        $document = $this->productionService->updateHeader(
            $production,
            $request->validated()
        );

        return ApiResponse::success(
            ProductionResponseData::fromModel($document),
            'Production updated successfully.'
        );
    }

    public function destroy(Production $production): JsonResponse
    {
        $this->productionService->delete($production);

        return ApiResponse::success(null, 'Production deleted successfully.');
    }

    public function syncLines(SyncProductionLinesRequest $request, Production $production): JsonResponse
    {
        $lines = $this->productionService->syncLines(
            $production,
            $request->validated('lines')
        );

        return ApiResponse::success(
            ProductionLineResponseData::collectionToArray($lines),
            'Production lines synced successfully.'
        );
    }

    public function post(Request $request, Production $production): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->productionService->post(
            $production,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            ProductionResponseData::fromModel($document),
            'Production posted successfully.'
        );
    }
}
