<?php

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockBalanceResponseData;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockBalanceService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockBalanceController extends Controller
{
    public function __construct(
        private readonly StockBalanceService $stockBalanceService
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

        return ListPagination::json(
            $this->stockBalanceService->paginate($filters, ListPagination::perPage($request)),
            fn (StockBalance $balance): array => StockBalanceResponseData::fromModel($balance),
            'Stock balances fetched successfully.'
        );
    }

    public function show(Request $request): JsonResponse
    {
        $itemId = (string) $request->query('item_id', '');
        $warehouseId = $request->integer('warehouse_id');

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

        $balance = $this->stockBalanceService->findForItemWarehouse($itemId, $warehouseId);

        if (! $balance) {
            return ApiResponse::success([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'quantity' => '0.000000',
                'item' => null,
                'warehouse' => null,
            ], 'No stock balance row yet; quantity is zero.');
        }

        return ApiResponse::success(
            StockBalanceResponseData::fromModel($balance),
            'Stock balance fetched successfully.'
        );
    }
}
