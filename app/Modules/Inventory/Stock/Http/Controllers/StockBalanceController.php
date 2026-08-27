<?php

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockBalanceResponseData;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockBalanceService;
use App\Modules\Inventory\Stock\Services\StockPipelineService;
use App\Modules\Inventory\Stock\Support\StockPipelineQuantities;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockBalanceController extends Controller
{
    public function __construct(
        private readonly StockBalanceService $stockBalanceService,
        private readonly StockPipelineService $stockPipelineService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'item_id' => $request->query('item_id') ?: null,
            'search' => ListPagination::search($request),
            'only_tracked' => $request->boolean('only_tracked', true),
            'only_with_stock' => $request->boolean('only_with_stock'),
        ];

        $paginator = $this->stockBalanceService->paginate($filters, ListPagination::perPage($request));
        $pipeline = $this->stockPipelineService->mapForBalances($paginator->getCollection());

        return ListPagination::json(
            $paginator,
            function (StockBalance $balance) use ($pipeline): array {
                $key = StockPipelineQuantities::pairKey((string) $balance->item_id, (int) $balance->warehouse_id);

                return StockBalanceResponseData::fromModel($balance, $pipeline[$key] ?? []);
            },
            'Stock balances fetched successfully.'
        );
    }

    public function show(Request $request): JsonResponse
    {
        $itemId = (string) $request->query('item_id', '');
        $warehouseId = $request->integer('warehouse_id');
        $lotId = $request->integer('lot_id') ?: null;

        if ($itemId === '' || ! $warehouseId) {
            return ApiResponse::error(
                'item_id and warehouse_id are required.',
                422,
                null,
                [
                    'item_id' => $itemId ? [] : ['The item id field is required.'],
                    'warehouse_id' => $warehouseId ? [] : ['The warehouse id field is required.'],
                ],
                null,
                null,
                'STOCK_BALANCE_PARAMS_REQUIRED'
            );
        }

        $balance = $this->stockBalanceService->findForItemWarehouse($itemId, $warehouseId, $lotId);
        $pipeline = $this->stockPipelineService->mapForPairs([[$itemId, $warehouseId]]);
        $pairPipeline = $pipeline[StockPipelineQuantities::pairKey($itemId, $warehouseId)] ?? StockPipelineQuantities::empty();

        if (! $balance) {
            $synthetic = new StockBalance([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'quantity' => 0,
                'unit_cost' => 0,
                'inventory_value' => 0,
            ]);

            return ApiResponse::success(
                StockBalanceResponseData::fromModel($synthetic, $pairPipeline),
                'No stock balance row yet; quantity is zero.'
            );
        }

        return ApiResponse::success(
            StockBalanceResponseData::fromModel($balance, $pairPipeline),
            'Stock balance fetched successfully.'
        );
    }
}
