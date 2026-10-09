<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\InvoiceProof\Http\Requests\RecordInvoiceProofDisputeRequest;
use App\Modules\InvoiceProof\Http\Requests\ResumeInvoiceProofPortalRequest;
use App\Modules\InvoiceProof\Http\Requests\UnlockInvoiceProofPortalRequest;
use App\Modules\InvoiceProof\Support\ProofPortalSession;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\InvoiceProof\Services\InvoicePdfService;
use App\Modules\InvoiceProof\Services\InvoiceProofPortalService;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class InvoiceProofPortalController extends Controller
{
    public function __construct(
        private readonly InvoiceProofPortalService $invoiceProofPortalService,
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function historyChallenge(): JsonResponse
    {
        return ApiResponse::success(
            $this->invoiceProofPortalService->historyChallenge(),
            'Buyer invoice list challenge issued.'
        );
    }

    public function history(UnlockInvoiceProofPortalRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->invoiceProofPortalService->history(
                (string) $request->validated('address'),
                (string) $request->validated('signature'),
                (string) $request->validated('nonce'),
            ),
            'Buyer invoices loaded.'
        );
    }

    public function show(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $salesInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        return ApiResponse::success(
            $this->invoiceProofPortalService->challenge($salesInvoice)->toArray(),
            'Invoice proof challenge issued.'
        );
    }

    public function unlock(UnlockInvoiceProofPortalRequest $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $salesInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        $address = (string) $request->validated('address');
        $data = $this->invoiceProofPortalService->unlock(
            $salesInvoice,
            $address,
            (string) $request->validated('signature'),
        );

        return ApiResponse::success(
            $this->withPortalSession($data->toArray(), $address, ProofPortalSession::ROLE_BUYER),
            'Invoice proof unlocked successfully.'
        );
    }

    public function resume(ResumeInvoiceProofPortalRequest $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $salesInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        $data = $this->invoiceProofPortalService->resume(
            $salesInvoice,
            (string) $request->validated('session'),
            (string) $request->validated('address'),
        );
        $payload = $data->toArray();
        $payload['portal_session'] = (string) $request->validated('session');

        return ApiResponse::success($payload, 'Invoice proof unlocked successfully.');
    }

    public function pdf(Request $request, SalesInvoice $salesInvoice): Response
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $salesInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );
        $this->invoiceProofPortalService->assertBuyerDownload(
            $salesInvoice,
            (string) $request->query('session', ''),
            (string) $request->query('address', ''),
        );

        try {
            $pdf = $this->invoicePdfService->renderSales($salesInvoice);
        } catch (InvalidArgumentException) {
            abort(422, 'This invoice is not ready to download.', [
                'X-Error-Code' => 'INVOICE_PDF_NOT_READY',
            ]);
        }

        return $pdf->download($this->invoicePdfService->downloadFilename(
            is_string($salesInvoice->invoice_number) ? $salesInvoice->invoice_number : '',
        ));
    }

    public function pdfPurchase(Request $request, PurchaseInvoice $purchaseInvoice): Response
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $purchaseInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );
        $this->invoiceProofPortalService->assertVendorDownload(
            $purchaseInvoice,
            (string) $request->query('session', ''),
            (string) $request->query('address', ''),
        );

        try {
            $pdf = $this->invoicePdfService->renderPurchase($purchaseInvoice);
        } catch (InvalidArgumentException) {
            abort(422, 'This invoice is not ready to download.', [
                'X-Error-Code' => 'INVOICE_PDF_NOT_READY',
            ]);
        }

        return $pdf->download($this->invoicePdfService->downloadFilename(
            is_string($purchaseInvoice->invoice_number) ? $purchaseInvoice->invoice_number : '',
        ));
    }

    public function dispute(RecordInvoiceProofDisputeRequest $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $salesInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        $txHash = $request->validated('tx_hash');
        $data = $this->invoiceProofPortalService->recordDispute(
            $salesInvoice,
            (string) $request->validated('reason'),
            is_string($txHash) ? $txHash : null,
        );

        return ApiResponse::success($data->toArray(), 'Invoice dispute recorded.');
    }

    public function disputePurchase(RecordInvoiceProofDisputeRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $purchaseInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        $txHash = $request->validated('tx_hash');
        $data = $this->invoiceProofPortalService->recordPurchaseDispute(
            $purchaseInvoice,
            (string) $request->validated('reason'),
            is_string($txHash) ? $txHash : null,
        );

        return ApiResponse::success($data->toArray(), 'Invoice dispute recorded.');
    }

    public function vendorHistoryChallenge(): JsonResponse
    {
        return ApiResponse::success(
            $this->invoiceProofPortalService->vendorHistoryChallenge(),
            'Vendor invoice list challenge issued.'
        );
    }

    public function vendorHistory(UnlockInvoiceProofPortalRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->invoiceProofPortalService->vendorHistory(
                (string) $request->validated('address'),
                (string) $request->validated('signature'),
                (string) $request->validated('nonce'),
            ),
            'Vendor invoices loaded.'
        );
    }

    public function showPurchase(Request $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $purchaseInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        return ApiResponse::success(
            $this->invoiceProofPortalService->challengePurchase($purchaseInvoice)->toArray(),
            'Invoice proof challenge issued.'
        );
    }

    public function unlockPurchase(UnlockInvoiceProofPortalRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $purchaseInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        $address = (string) $request->validated('address');
        $data = $this->invoiceProofPortalService->unlockPurchase(
            $purchaseInvoice,
            $address,
            (string) $request->validated('signature'),
        );

        return ApiResponse::success(
            $this->withPortalSession($data->toArray(), $address, ProofPortalSession::ROLE_VENDOR),
            'Invoice proof unlocked successfully.'
        );
    }

    public function resumePurchase(ResumeInvoiceProofPortalRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->invoiceProofPortalService->assertValidLink(
            (string) $purchaseInvoice->id,
            $request->query('exp'),
            $request->query('sig'),
        );

        $data = $this->invoiceProofPortalService->resumePurchase(
            $purchaseInvoice,
            (string) $request->validated('session'),
            (string) $request->validated('address'),
        );
        $payload = $data->toArray();
        $payload['portal_session'] = (string) $request->validated('session');

        return ApiResponse::success($payload, 'Invoice proof unlocked successfully.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withPortalSession(array $payload, string $address, string $role): array
    {
        $signer = WalletAddress::normalize($address);
        if ($signer !== null) {
            $payload['portal_session'] = ProofPortalSession::issue((string) tenant('id'), $signer, $role);
        }

        return $payload;
    }
}
