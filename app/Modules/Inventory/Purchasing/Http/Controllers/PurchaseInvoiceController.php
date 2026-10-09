<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Purchasing\DTOs\PurchaseInvoiceLineResponseData;
use App\Modules\Inventory\Purchasing\DTOs\PurchaseInvoiceResponseData;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Http\Requests\StorePurchaseInvoiceRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\SyncPurchaseInvoiceLinesRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\UpdatePurchaseInvoiceRequest;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\InvoiceProof\Http\Requests\DiscloseInvoiceProofRequest;
use App\Modules\InvoiceProof\Http\Requests\RecordInvoiceProofDisputeRequest;
use App\Modules\InvoiceProof\Services\InvoiceChainIssueLookup;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use App\Modules\InvoiceProof\Services\InvoiceChainStatusLookup;
use App\Modules\InvoiceProof\Services\InvoicePdfService;
use App\Modules\InvoiceProof\Services\InvoiceProofDisclosureService;
use App\Modules\InvoiceProof\Services\InvoiceProofPortalService;
use App\Modules\InvoiceProof\Services\InvoiceProofVerificationService;
use App\Modules\InvoiceProof\Models\LinkedPurchaseOffer;
use App\Modules\InvoiceProof\Services\LinkedPurchaseDisclosureService;
use App\Modules\InvoiceProof\Services\LinkedPurchaseOfferService;
use App\Services\PermissionService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private readonly PurchaseInvoiceService $purchaseInvoiceService,
        private readonly InvoiceProofVerificationService $invoiceProofVerificationService,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
        private readonly InvoiceProofPortalService $invoiceProofPortalService,
        private readonly InvoiceProofDisclosureService $invoiceProofDisclosureService,
        private readonly PermissionService $permissionService,
        private readonly InvoiceChainIssueLookup $invoiceChainIssueLookup,
        private readonly InvoiceChainStatusLookup $invoiceChainStatusLookup,
        private readonly LinkedPurchaseDisclosureService $linkedPurchaseDisclosureService,
        private readonly InvoicePdfService $invoicePdfService,
        private readonly LinkedPurchaseOfferService $linkedPurchaseOfferService,
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

        return ListPagination::jsonMapped(
            $this->purchaseInvoiceService->list($filters, ListPagination::perPage($request, 50)),
            function ($invoices): array {
                $chainIssues = $this->invoiceChainIssueLookup->forInvoices($invoices->pluck('id'));
                $chainStatuses = $this->invoiceChainStatusLookup->forPurchaseInvoices($invoices->pluck('id'));

                return $invoices->map(
                    fn (PurchaseInvoice $invoice): array => PurchaseInvoiceResponseData::fromModel(
                        $invoice,
                        false,
                        $chainIssues[(string) $invoice->id] ?? null,
                        $chainStatuses[(string) $invoice->id] ?? null,
                    ),
                )->values()->all();
            },
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
        $invoice = $this->purchaseInvoiceService->find($purchaseInvoice->id);

        return ApiResponse::success(
            PurchaseInvoiceResponseData::fromModel(
                $invoice,
                true,
                $this->invoiceChainIssueLookup->forInvoices([$invoice->id])[(string) $invoice->id] ?? null,
                $this->invoiceChainStatusLookup->forPurchaseInvoices([$invoice->id])[(string) $invoice->id] ?? null,
            ),
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

    public function reissue(Request $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $user = $request->user();
        if (
            $purchaseInvoice->status === PurchaseInvoiceStatus::Posted
            && ($user === null || ! $this->permissionService->userHas('purchase_invoices', 'reverse', $user))
        ) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $invoice = $this->purchaseInvoiceService->reissue(
            $purchaseInvoice,
            $user !== null ? (string) $user->id : null
        );

        return ApiResponse::created(
            PurchaseInvoiceResponseData::fromModel($invoice),
            'Purchase invoice reissued successfully.'
        );
    }

    public function verify(Request $request, PurchaseInvoice $purchaseInvoice): JsonResponse
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

        $invoice = $this->purchaseInvoiceService->find($purchaseInvoice->id);

        return ApiResponse::success(
            $this->invoiceProofVerificationService->verifyPurchaseInvoice($invoice)->toArray(),
            'Invoice proof checked successfully.'
        );
    }

    public function approveAsBuyer(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $invoice = $this->purchaseInvoiceService->find($purchaseInvoice->id);
        $this->invoiceChainRegistrationService->assertPurchaseBuyerWalletAction($invoice);

        return ApiResponse::success(
            $this->invoiceProofVerificationService->verifyPurchaseInvoice($invoice)->toArray(),
            'Invoice approved as buyer.'
        );
    }

    public function recordDispute(RecordInvoiceProofDisputeRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
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

        $invoice = $this->purchaseInvoiceService->find($purchaseInvoice->id);
        if ($invoice->linked_proof_id !== null) {
            $txHash = $request->validated('tx_hash');
            $this->invoiceChainRegistrationService->storeExternalDisputeReason(
                $invoice,
                (string) $request->validated('reason'),
                is_string($txHash) ? $txHash : null,
            );

            return ApiResponse::success(
                $this->invoiceProofVerificationService->verifyPurchaseInvoice($invoice)->toArray(),
                'Invoice dispute recorded.'
            );
        }

        abort(422, 'The company that posted this invoice cannot dispute it. Reverse the invoice instead.', [
            'X-Error-Code' => 'PURCHASE_INVOICE_BUYER_CANNOT_DISPUTE',
        ]);
    }

    public function importLinkedProof(Request $request): JsonResponse
    {
        $disclosure = $request->input('disclosure');
        if (! is_array($disclosure)) {
            abort(422, 'Upload the supplier disclosure for this proof.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_DISCLOSURE_REQUIRED',
            ]);
        }

        return ApiResponse::success(
            $this->linkedPurchaseDisclosureService->import($disclosure),
            'Supplier disclosure imported successfully.'
        );
    }

    public function showLinkedOffer(LinkedPurchaseOffer $linkedPurchaseOffer): JsonResponse
    {
        return ApiResponse::success(
            $this->linkedPurchaseOfferService->open($linkedPurchaseOffer),
            'Supplier invoice fetched successfully.'
        );
    }

    public function previewLinkedProof(string $proofId): JsonResponse
    {
        return ApiResponse::success(
            $this->invoiceChainRegistrationService->previewExternalProof($proofId),
            'Supplier proof fetched successfully.'
        );
    }

    public function vendorPortalLink(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->abortLinkedProofShare($purchaseInvoice);
        return ApiResponse::success(
            $this->invoiceProofPortalService->issueVendorLink($purchaseInvoice)->toArray(),
            'Vendor portal link created successfully.'
        );
    }

    public function proofFields(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->abortLinkedProofShare($purchaseInvoice);
        return ApiResponse::success(
            $this->invoiceProofDisclosureService->fieldsForPurchase($purchaseInvoice)->toArray(),
            'Invoice proof fields fetched successfully.'
        );
    }

    public function proofDisclosure(DiscloseInvoiceProofRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->abortLinkedProofShare($purchaseInvoice);
        /** @var list<string> $fields */
        $fields = array_values($request->validated('fields'));

        return ApiResponse::success(
            $this->invoiceProofDisclosureService->disclosePurchase($purchaseInvoice, $fields)->toArray(),
            'Invoice proof disclosure created successfully.'
        );
    }

    public function pdf(PurchaseInvoice $purchaseInvoice): Response
    {
        try {
            $pdf = $this->invoicePdfService->renderPurchase($purchaseInvoice);
        } catch (InvalidArgumentException) {
            abort(422, 'This invoice is not ready to download.', [
                'X-Error-Code' => 'INVOICE_PDF_NOT_READY',
            ]);
        }

        $number = is_string($purchaseInvoice->invoice_number) ? $purchaseInvoice->invoice_number : '';

        return $pdf->download($this->invoicePdfService->downloadFilename($number));
    }

    private function abortLinkedProofShare(PurchaseInvoice $invoice): void
    {
        if ($invoice->linked_proof_id !== null) {
            abort(422, 'This purchase invoice uses the supplier proof. There is no separate vendor link.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_PROOF',
            ]);
        }
    }
}
