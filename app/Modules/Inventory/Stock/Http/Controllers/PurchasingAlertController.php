<?php

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\Services\PurchasingAlertService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchasingAlertController extends Controller
{
    public function __construct(
        private readonly PurchasingAlertService $purchasingAlertService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'item_id' => $request->string('item_id')->toString() ?: null,
            'search' => ListPagination::search($request),
            'status' => $request->string('status')->toString() ?: null,
            'only_alerts' => $request->has('only_alerts')
                ? $request->boolean('only_alerts')
                : true,
        ];

        $paginator = $this->purchasingAlertService->paginate(
            $filters,
            ListPagination::perPage($request)
        );

        return ApiResponse::success(
            $paginator->toArray(),
            'Purchasing alerts fetched successfully.'
        );
    }

    public function summary(): JsonResponse
    {
        return ApiResponse::success(
            ['count' => $this->purchasingAlertService->alertCount()],
            'Purchasing alert summary fetched successfully.'
        );
    }

    public function show(int $replenishmentId): JsonResponse
    {
        return ApiResponse::success(
            $this->purchasingAlertService->find($replenishmentId),
            'Purchasing alert fetched successfully.'
        );
    }
}
