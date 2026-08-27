<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\InventoryLotResponseData;
use App\Modules\Inventory\Stock\Http\Requests\UpdateInventoryLotRequest;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\InventoryLotService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryLotController extends Controller
{
    public function __construct(
        private readonly InventoryLotService $inventoryLotService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $itemId = (string) $request->query('item_id', '');
        if ($itemId === '') {
            return ApiResponse::error(
                'item_id is required.',
                422,
                null,
                ['item_id' => ['The item id field is required.']],
                null,
                null,
                'STOCK_LOT_ITEM_REQUIRED'
            );
        }

        $warehouseId = $request->integer('warehouse_id') ?: null;

        return ApiResponse::success(
            $this->inventoryLotService->listForItemWarehouse($itemId, $warehouseId)->all(),
            'Inventory lots fetched successfully.'
        );
    }

    public function catalog(Request $request): JsonResponse
    {
        $filters = [
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'item_id' => $request->query('item_id') ?: null,
            'search' => ListPagination::search($request),
            'expired' => $request->boolean('expired'),
            'missing_expiry' => $request->boolean('missing_expiry'),
            'only_with_stock' => $request->boolean('only_with_stock'),
        ];

        return ListPagination::json(
            $this->inventoryLotService->paginate($filters, ListPagination::perPage($request)),
            fn (StockBalance $balance): array => InventoryLotResponseData::fromBalance($balance),
            'Inventory lots fetched successfully.'
        );
    }

    public function update(UpdateInventoryLotRequest $request, InventoryLot $inventory_lot): JsonResponse
    {
        $updated = $this->inventoryLotService->updateExpiry(
            $inventory_lot,
            $request->validated()['expiry_date'] ?? null,
        );

        return ApiResponse::success(
            InventoryLotResponseData::fromModel($updated),
            'Inventory lot updated successfully.'
        );
    }
}
