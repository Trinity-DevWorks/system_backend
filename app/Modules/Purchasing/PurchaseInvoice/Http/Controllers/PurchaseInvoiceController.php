<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Purchasing\PurchaseInvoice\DTOs\PurchaseInvoiceLineResponseData;
use App\Modules\Purchasing\PurchaseInvoice\DTOs\PurchaseInvoiceResponseData;
use App\Modules\Purchasing\PurchaseInvoice\Http\Requests\StorePurchaseInvoiceRequest;
use App\Modules\Purchasing\PurchaseInvoice\Http\Requests\SyncPurchaseInvoiceLinesRequest;
use App\Modules\Purchasing\PurchaseInvoice\Http\Requests\UpdatePurchaseInvoiceRequest;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoice;
use App\Modules\Purchasing\PurchaseInvoice\Services\PurchaseInvoiceService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private readonly PurchaseInvoiceService $purchaseInvoiceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'supplier_id' => $request->string('supplier_id')->toString() ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->purchaseInvoiceService->list($filters, ListPagination::perPage($request, 50)),
            fn (PurchaseInvoice $invoice): array => PurchaseInvoiceResponseData::fromModel($invoice, false),
            'Purchase invoices fetched successfully.'
        );
    }

    public function store(StorePurchaseInvoiceRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->purchaseInvoiceService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            PurchaseInvoiceResponseData::fromModel($invoice),
            'Purchase invoice created successfully.'
        );
    }

    public function show(PurchaseInvoice $purchase_invoice): JsonResponse
    {
        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel($this->purchaseInvoiceService->find($purchase_invoice->id)),
            'Purchase invoice fetched successfully.'
        );
    }

    public function update(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $invoice = $this->purchaseInvoiceService->updateHeader(
            $purchase_invoice,
            $request->validated()
        );

        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel($invoice),
            'Purchase invoice updated successfully.'
        );
    }

    public function destroy(PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $this->purchaseInvoiceService->delete($purchase_invoice);

        return ApiResponse::success(null, 'Purchase invoice deleted successfully.');
    }

    public function syncLines(SyncPurchaseInvoiceLinesRequest $request, PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $lines = $this->purchaseInvoiceService->syncLines(
            $purchase_invoice,
            $request->validated('lines')
        );

        return ApiResponse::success(
            PurchaseInvoiceLineResponseData::collectionToArray($lines),
            'Purchase invoice lines synced successfully.'
        );
    }

    public function post(Request $request, PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->purchaseInvoiceService->post(
            $purchase_invoice,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel($invoice),
            'Purchase invoice posted successfully.'
        );
    }
}
