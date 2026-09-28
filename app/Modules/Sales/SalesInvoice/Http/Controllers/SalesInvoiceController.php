<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use App\Modules\InvoiceProof\Services\InvoiceProofPortalService;
use App\Modules\InvoiceProof\Services\InvoiceProofVerificationService;
use App\Modules\Sales\SalesInvoice\DTOs\SalesInvoiceLineResponseData;
use App\Modules\Sales\SalesInvoice\DTOs\SalesInvoiceResponseData;
use App\Modules\Sales\SalesInvoice\Http\Requests\StoreSalesInvoiceRequest;
use App\Modules\Sales\SalesInvoice\Http\Requests\SyncSalesInvoiceLinesRequest;
use App\Modules\Sales\SalesInvoice\Http\Requests\UpdateSalesInvoiceRequest;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Services\SalesInvoiceService;
use App\Services\PermissionService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesInvoiceController extends Controller
{
    public function __construct(
        private readonly SalesInvoiceService $salesInvoiceService,
        private readonly InvoiceProofVerificationService $invoiceProofVerificationService,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
        private readonly InvoiceProofPortalService $invoiceProofPortalService,
        private readonly PermissionService $permissionService,
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
            $this->salesInvoiceService->list($filters, ListPagination::perPage($request, 50)),
            fn (SalesInvoice $invoice): array => SalesInvoiceResponseData::fromModel($invoice, false),
            'Sales invoices fetched successfully.'
        );
    }

    public function store(StoreSalesInvoiceRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->salesInvoiceService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            SalesInvoiceResponseData::fromModel($invoice),
            'Sales invoice created successfully.'
        );
    }

    public function show(SalesInvoice $salesInvoice): JsonResponse
    {
        return ApiResponse::success(
            SalesInvoiceResponseData::fromModel($this->salesInvoiceService->find($salesInvoice->id)),
            'Sales invoice fetched successfully.'
        );
    }

    public function update(UpdateSalesInvoiceRequest $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $invoice = $this->salesInvoiceService->updateHeader(
            $salesInvoice,
            $request->validated()
        );

        return ApiResponse::success(
            SalesInvoiceResponseData::fromModel($invoice),
            'Sales invoice updated successfully.'
        );
    }

    public function destroy(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->salesInvoiceService->delete($salesInvoice);

        return ApiResponse::success(null, 'Sales invoice deleted successfully.');
    }

    public function syncLines(SyncSalesInvoiceLinesRequest $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $lines = $this->salesInvoiceService->syncLines(
            $salesInvoice,
            $request->validated('lines')
        );

        $invoice = $this->salesInvoiceService->find($salesInvoice->id);

        return ApiResponse::success(
            [
                'lines' => SalesInvoiceLineResponseData::collectionToArray($lines),
                'invoice' => SalesInvoiceResponseData::fromModel($invoice),
            ],
            'Sales invoice lines synced successfully.'
        );
    }

    public function post(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->salesInvoiceService->post(
            $salesInvoice,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            SalesInvoiceResponseData::fromModel($invoice),
            'Sales invoice posted successfully.'
        );
    }

    public function reverse(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $userId = $request->user()?->id;
        $invoice = $this->salesInvoiceService->reverse(
            $salesInvoice,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            SalesInvoiceResponseData::fromModel($invoice),
            'Sales invoice reversed successfully.'
        );
    }

    public function verify(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $user = $request->user();
        $canReadProof = $user !== null && (
            $this->permissionService->userHas('invoice_proofs', 'view', $user)
            || $this->permissionService->userHas('invoice_proofs', 'edit', $user)
        );
        if (! $canReadProof) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            return ApiResponse::error(
                'Invoice proofs are disabled for this company.',
                403,
                null,
                [],
                null,
                null,
                'INVOICE_PROOFS_DISABLED'
            );
        }

        $invoice = $this->salesInvoiceService->find($salesInvoice->id);

        return ApiResponse::success(
            $this->invoiceProofVerificationService->verifySalesInvoice($invoice)->toArray(),
            'Invoice proof checked successfully.'
        );
    }

    public function approveAsCompany(SalesInvoice $salesInvoice): JsonResponse
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            return ApiResponse::error(
                'Invoice proofs are disabled for this company.',
                403,
                null,
                [],
                null,
                null,
                'INVOICE_PROOFS_DISABLED'
            );
        }

        $invoice = $this->salesInvoiceService->find($salesInvoice->id);
        $this->invoiceChainRegistrationService->approveAsCompany($invoice);

        return ApiResponse::success(
            $this->invoiceProofVerificationService->verifySalesInvoice($invoice)->toArray(),
            'Invoice approved as company.'
        );
    }

    public function buyerPortalLink(SalesInvoice $salesInvoice): JsonResponse
    {
        return ApiResponse::success(
            $this->invoiceProofPortalService->issueLink($salesInvoice)->toArray(),
            'Buyer portal link created successfully.'
        );
    }

    public function itemAvailability(Request $request): JsonResponse
    {
        $itemId = trim((string) $request->query('item_id', ''));
        if ($itemId === '') {
            return ApiResponse::error('Item is required.', 422, null, ['item_id' => ['Item is required.']], null, null, 'ITEM_REQUIRED');
        }

        return ApiResponse::success(
            $this->salesInvoiceService->itemAvailability($itemId),
            'Item availability fetched successfully.'
        );
    }
}
