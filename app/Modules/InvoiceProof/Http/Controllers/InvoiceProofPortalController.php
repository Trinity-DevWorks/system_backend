<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\InvoiceProof\Http\Requests\RecordInvoiceProofDisputeRequest;
use App\Modules\InvoiceProof\Http\Requests\UnlockInvoiceProofPortalRequest;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\InvoiceProof\Services\InvoiceProofPortalService;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceProofPortalController extends Controller
{
    public function __construct(
        private readonly InvoiceProofPortalService $invoiceProofPortalService,
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

        $data = $this->invoiceProofPortalService->unlock(
            $salesInvoice,
            (string) $request->validated('address'),
            (string) $request->validated('signature'),
        );

        return ApiResponse::success($data->toArray(), 'Invoice proof unlocked successfully.');
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

        $data = $this->invoiceProofPortalService->unlockPurchase(
            $purchaseInvoice,
            (string) $request->validated('address'),
            (string) $request->validated('signature'),
        );

        return ApiResponse::success($data->toArray(), 'Invoice proof unlocked successfully.');
    }
}
