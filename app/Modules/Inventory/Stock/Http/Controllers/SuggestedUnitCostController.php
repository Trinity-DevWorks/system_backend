<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\Services\SuggestedUnitCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuggestedUnitCostController extends Controller
{
    public function __construct(
        private readonly SuggestedUnitCostService $suggestedUnitCostService,
    ) {}

    public function show(Request $request): JsonResponse
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
                'SUGGESTED_UNIT_COST_PARAMS_REQUIRED'
            );
        }

        $warehouseId = $request->integer('warehouse_id') ?: null;
        $supplierId = $request->query('supplier_id');
        $supplierId = is_string($supplierId) && $supplierId !== '' ? $supplierId : null;
        $itemUomId = $request->integer('item_uom_id') ?: null;
        $lotId = $request->integer('lot_id') ?: null;

        $suggestion = $this->suggestedUnitCostService->suggest(
            $itemId,
            $warehouseId,
            $supplierId,
            $itemUomId,
            $lotId,
        );

        return ApiResponse::success($suggestion, 'Suggested unit cost fetched successfully.');
    }
}
