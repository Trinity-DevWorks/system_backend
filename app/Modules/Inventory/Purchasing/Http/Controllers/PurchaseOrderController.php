<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Purchasing\DTOs\PurchaseOrderLineResponseData;
use App\Modules\Inventory\Purchasing\DTOs\PurchaseOrderResponseData;
use App\Modules\Inventory\Purchasing\Http\Requests\CreatePurchaseOrdersFromAlertsRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\SyncPurchaseOrderLinesRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Services\PurchaseOrderFromAlertsService;
use App\Modules\Inventory\Purchasing\Services\PurchaseOrderPdfService;
use App\Modules\Inventory\Purchasing\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly PurchaseOrderFromAlertsService $purchaseOrderFromAlertsService,
        private readonly PurchaseOrderPdfService $purchaseOrderPdfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'supplier_id' => $request->string('supplier_id')->toString() ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'limit' => $request->integer('limit') ?: 100,
        ];

        return ApiResponse::success(
            PurchaseOrderResponseData::collectionToArray($this->purchaseOrderService->list($filters)),
            'Purchase orders fetched successfully.'
        );
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $order = $this->purchaseOrderService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            PurchaseOrderResponseData::fromModel($order),
            'Purchase order created successfully.'
        );
    }

    public function fromAlerts(CreatePurchaseOrdersFromAlertsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;

        $result = $this->purchaseOrderFromAlertsService->handle(
            $validated['replenishment_ids'],
            $validated['supplier_overrides'] ?? [],
            (bool) ($validated['preview'] ?? false),
            $userId !== null ? (string) $userId : null,
        );

        $preview = (bool) ($validated['preview'] ?? false);
        $createdCount = count($result['purchase_orders']);

        if ($preview) {
            return ApiResponse::success($result, 'Purchase order preview generated successfully.');
        }

        if ($createdCount === 0) {
            return ApiResponse::success($result, 'No purchase orders were created.');
        }

        return ApiResponse::created(
            $result,
            $createdCount === 1
                ? 'Purchase order created successfully.'
                : 'Purchase orders created successfully.'
        );
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success(
            PurchaseOrderResponseData::fromModel($this->purchaseOrderService->find($purchaseOrder->id)),
            'Purchase order fetched successfully.'
        );
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->purchaseOrderService->updateHeader(
            $purchaseOrder,
            $request->validated()
        );

        return ApiResponse::success(
            PurchaseOrderResponseData::fromModel($order),
            'Purchase order updated successfully.'
        );
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->purchaseOrderService->delete($purchaseOrder);

        return ApiResponse::success(null, 'Purchase order deleted successfully.');
    }

    public function syncLines(SyncPurchaseOrderLinesRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $lines = $this->purchaseOrderService->syncLines(
            $purchaseOrder,
            $request->validated('lines')
        );

        return ApiResponse::success(
            PurchaseOrderLineResponseData::collectionToArray($lines),
            'Purchase order lines synced successfully.'
        );
    }

    public function confirm(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $userId = $request->user()?->id;
        $order = $this->purchaseOrderService->confirm(
            $purchaseOrder,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            PurchaseOrderResponseData::fromModel($order),
            'Purchase order confirmed successfully.'
        );
    }

    public function cancel(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->purchaseOrderService->cancel($purchaseOrder);

        return ApiResponse::success(
            PurchaseOrderResponseData::fromModel($order),
            'Purchase order cancelled successfully.'
        );
    }

    public function pdf(PurchaseOrder $purchaseOrder): Response
    {
        $pdf = $this->purchaseOrderPdfService->render($purchaseOrder);

        return $pdf->download($this->purchaseOrderPdfService->downloadFilename($purchaseOrder));
    }

    public function markAsSent(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $userId = $request->user()?->id;
        $order = $this->purchaseOrderService->markAsSent(
            $purchaseOrder,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            PurchaseOrderResponseData::fromModel($order),
            'Purchase order marked as sent successfully.'
        );
    }
}
