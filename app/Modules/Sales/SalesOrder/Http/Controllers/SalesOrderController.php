<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\SalesInvoice\DTOs\SalesInvoiceResponseData;
use App\Modules\Sales\SalesOrder\DTOs\SalesOrderLineResponseData;
use App\Modules\Sales\SalesOrder\DTOs\SalesOrderResponseData;
use App\Modules\Sales\SalesOrder\Http\Requests\InvoiceSalesOrderRequest;
use App\Modules\Sales\SalesOrder\Http\Requests\StoreSalesOrderRequest;
use App\Modules\Sales\SalesOrder\Http\Requests\SyncSalesOrderLinesRequest;
use App\Modules\Sales\SalesOrder\Http\Requests\UpdateSalesOrderRequest;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesOrder\Services\SalesOrderPdfService;
use App\Modules\Sales\SalesOrder\Services\SalesOrderService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService,
        private readonly SalesOrderPdfService $salesOrderPdfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'customer_id' => $request->string('customer_id')->toString() ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->salesOrderService->list($filters, ListPagination::perPage($request, 50)),
            fn (SalesOrder $order): array => SalesOrderResponseData::fromModel($order, false),
            'Sales orders fetched successfully.'
        );
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $order = $this->salesOrderService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::created(
            SalesOrderResponseData::fromModel($order),
            'Sales order created successfully.'
        );
    }

    public function show(SalesOrder $salesOrder): JsonResponse
    {
        return ApiResponse::success(
            SalesOrderResponseData::fromModel($this->salesOrderService->find($salesOrder->id)),
            'Sales order fetched successfully.'
        );
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        $order = $this->salesOrderService->updateHeader($salesOrder, $request->validated());

        return ApiResponse::success(
            SalesOrderResponseData::fromModel($order),
            'Sales order updated successfully.'
        );
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        $this->salesOrderService->delete($salesOrder);

        return ApiResponse::success(null, 'Sales order deleted successfully.');
    }

    public function syncLines(SyncSalesOrderLinesRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        $lines = $this->salesOrderService->syncLines($salesOrder, $request->validated('lines'));
        $order = $this->salesOrderService->find($salesOrder->id);

        return ApiResponse::success(
            [
                'lines' => SalesOrderLineResponseData::collectionToArray($lines),
                'order' => SalesOrderResponseData::fromModel($order),
            ],
            'Sales order lines synced successfully.'
        );
    }

    public function confirm(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $userId = $request->user()?->id;

        return ApiResponse::success(
            SalesOrderResponseData::fromModel($this->salesOrderService->confirm(
                $salesOrder,
                $userId !== null ? (string) $userId : null,
            )),
            'Sales order confirmed successfully.'
        );
    }

    public function cancel(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $userId = $request->user()?->id;

        return ApiResponse::success(
            SalesOrderResponseData::fromModel($this->salesOrderService->cancel(
                $salesOrder,
                $userId !== null ? (string) $userId : null,
            )),
            'Sales order cancelled successfully.'
        );
    }

    public function remainingLines(SalesOrder $salesOrder): JsonResponse
    {
        return ApiResponse::success(
            $this->salesOrderService->remainingLines($salesOrder),
            'Remaining sales order lines fetched successfully.'
        );
    }

    public function invoice(InvoiceSalesOrderRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->salesOrderService->createInvoice(
            $salesOrder,
            $request->validated('lines'),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::created(
            SalesInvoiceResponseData::fromModel($invoice),
            'Sales invoice created from the order successfully.'
        );
    }

    public function pdf(Request $request, SalesOrder $salesOrder): Response
    {
        $pdf = $this->salesOrderPdfService->render(
            $salesOrder,
            $request->query('locale')
        );

        return $pdf->download($this->salesOrderPdfService->downloadFilename($salesOrder));
    }
}
