<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\StockAdjustmentReasonResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockAdjustmentReasonRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateStockAdjustmentReasonRequest;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use App\Modules\Inventory\Stock\Services\StockAdjustmentReasonService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentReasonController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly StockAdjustmentReasonService $stockAdjustmentReasonService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn () => StockAdjustmentReasonResponseData::collectionToArray(
                $this->stockAdjustmentReasonService->list(activeOnly: true)
            ),
            'Adjustment reason names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->stockAdjustmentReasonService->paginate(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (StockAdjustmentReason $reason): array => StockAdjustmentReasonResponseData::fromModel($reason),
            'Adjustment reasons fetched successfully.'
        );
    }

    public function store(StoreStockAdjustmentReasonRequest $request): JsonResponse
    {
        $reason = $this->stockAdjustmentReasonService->create($request->validated());

        return ApiResponse::created(
            StockAdjustmentReasonResponseData::fromModel($reason),
            'Adjustment reason created successfully.'
        );
    }

    public function show(StockAdjustmentReason $stock_adjustment_reason): JsonResponse
    {
        return ApiResponse::success(
            StockAdjustmentReasonResponseData::fromModel($stock_adjustment_reason),
            'Adjustment reason fetched successfully.'
        );
    }

    public function update(
        UpdateStockAdjustmentReasonRequest $request,
        StockAdjustmentReason $stock_adjustment_reason
    ): JsonResponse {
        $updated = $this->stockAdjustmentReasonService->update(
            $stock_adjustment_reason,
            $request->validated()
        );

        return ApiResponse::success(
            StockAdjustmentReasonResponseData::fromModel($updated),
            'Adjustment reason updated successfully.'
        );
    }

    public function destroy(StockAdjustmentReason $stock_adjustment_reason): JsonResponse
    {
        $this->stockAdjustmentReasonService->delete($stock_adjustment_reason);

        return ApiResponse::success(null, 'Adjustment reason deleted successfully.');
    }
}
