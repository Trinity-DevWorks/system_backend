<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\DeliveryNote\DTOs\DeliveryNoteResponseData;
use App\Modules\Sales\DeliveryNote\Http\Requests\SaveDeliveryNoteRequest;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\DeliveryNote\Services\DeliveryNotePdfService;
use App\Modules\Sales\DeliveryNote\Services\DeliveryNoteService;
use App\Modules\Sales\SalesInvoice\DTOs\SalesInvoiceResponseData;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeliveryNoteController extends Controller
{
    public function __construct(
        private readonly DeliveryNoteService $service,
        private readonly DeliveryNotePdfService $pdfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->service->paginate([
                'status' => (string) $request->query('status', ''),
                'customer_id' => (string) $request->query('customer_id', ''),
                'sales_order_id' => (string) $request->query('sales_order_id', ''),
                'search' => ListPagination::search($request),
            ], ListPagination::perPage($request)),
            fn (DeliveryNote $note): array => DeliveryNoteResponseData::fromModel($note),
            'Delivery notes fetched successfully.'
        );
    }

    public function store(SaveDeliveryNoteRequest $request): JsonResponse
    {
        return ApiResponse::created(
            DeliveryNoteResponseData::fromModel($this->service->create($request->validated(), $this->userId($request))),
            'Delivery note created successfully.'
        );
    }

    public function show(DeliveryNote $delivery_note): JsonResponse
    {
        return ApiResponse::success(DeliveryNoteResponseData::fromModel($this->service->find((string) $delivery_note->id)), 'Delivery note fetched successfully.');
    }

    public function update(SaveDeliveryNoteRequest $request, DeliveryNote $delivery_note): JsonResponse
    {
        return ApiResponse::success(
            DeliveryNoteResponseData::fromModel($this->service->update($delivery_note, $request->validated())),
            'Delivery note updated successfully.'
        );
    }

    public function destroy(DeliveryNote $delivery_note): JsonResponse
    {
        $this->service->delete($delivery_note);

        return ApiResponse::success(null, 'Delivery note deleted successfully.');
    }

    public function post(Request $request, DeliveryNote $delivery_note): JsonResponse
    {
        return ApiResponse::success(
            DeliveryNoteResponseData::fromModel($this->service->post($delivery_note, $this->userId($request))),
            'Delivery note posted successfully.'
        );
    }

    public function reverse(Request $request, DeliveryNote $delivery_note): JsonResponse
    {
        return ApiResponse::success(
            DeliveryNoteResponseData::fromModel($this->service->reverse($delivery_note, $this->userId($request))),
            'Delivery note reversed successfully.'
        );
    }

    public function invoice(Request $request, DeliveryNote $delivery_note): JsonResponse
    {
        return ApiResponse::created(
            SalesInvoiceResponseData::fromModel($this->service->createInvoice($delivery_note, $this->userId($request))),
            'Draft invoice created from the delivery note.'
        );
    }

    public function fromOrder(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        return ApiResponse::created(
            DeliveryNoteResponseData::fromModel($this->service->createFromOrder($salesOrder, $this->userId($request))),
            'Draft delivery note created from the sales order.'
        );
    }

    public function orderTemplate(SalesOrder $salesOrder): JsonResponse
    {
        return ApiResponse::success($this->service->orderTemplate($salesOrder), 'Delivery template fetched successfully.');
    }

    public function deliverableOrders(Request $request): JsonResponse
    {
        $customerId = $request->validate(['customer_id' => ['required', 'uuid']])['customer_id'];

        return ApiResponse::success($this->service->deliverableOrders((string) $customerId), 'Deliverable orders fetched successfully.');
    }

    public function pdf(Request $request, DeliveryNote $delivery_note): Response
    {
        return $this->pdfService->download($delivery_note, $request->query('locale') !== null ? (string) $request->query('locale') : null);
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
