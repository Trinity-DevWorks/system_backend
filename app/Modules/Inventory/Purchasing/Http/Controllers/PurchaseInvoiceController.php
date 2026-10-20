<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Purchasing\DTOs\PurchaseInvoiceLineResponseData;
use App\Modules\Inventory\Purchasing\DTOs\PurchaseInvoiceResponseData;
use App\Modules\Inventory\Purchasing\Http\Requests\StorePurchaseInvoiceRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\SyncPurchaseInvoiceLinesRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\UpdatePurchaseInvoiceRequest;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Services\PurchaseInvoiceService;
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

    public function show(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel($this->purchaseInvoiceService->find($purchaseInvoice->id)),
            'Purchase invoice fetched successfully.'
        );
    }

    public function update(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $invoice = $this->purchaseInvoiceService->updateHeader($purchaseInvoice, $request->validated());

        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel($invoice),
            'Purchase invoice updated successfully.'
        );
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->purchaseInvoiceService->delete($purchaseInvoice);

        return ApiResponse::success(null, 'Purchase invoice deleted successfully.');
    }

    public function syncLines(SyncPurchaseInvoiceLinesRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $lines = $this->purchaseInvoiceService->syncLines($purchaseInvoice, $request->validated('lines'));
        $invoice = $this->purchaseInvoiceService->find($purchaseInvoice->id);

        return ApiResponse::success(
            [
                'lines' => PurchaseInvoiceLineResponseData::collectionToArray($lines),
                'invoice' => PurchaseInvoiceResponseData::fromModel($invoice),
            ],
            'Purchase invoice lines synced successfully.'
        );
    }

    public function post(Request $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->purchaseInvoiceService->post(
            $purchaseInvoice,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel($invoice),
            'Purchase invoice posted successfully.'
        );
    }

    public function reverse(Request $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->purchaseInvoiceService->reverse(
            $purchaseInvoice,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel($invoice),
            'Purchase invoice reversed successfully.'
        );
    }
}
