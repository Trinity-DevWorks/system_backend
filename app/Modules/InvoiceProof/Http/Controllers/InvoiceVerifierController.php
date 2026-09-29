<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\InvoiceProof\DTOs\InvoiceVerifierData;
use App\Modules\InvoiceProof\DTOs\InvoiceVerifierResponseData;
use App\Modules\InvoiceProof\Http\Requests\StoreInvoiceVerifierRequest;
use App\Modules\InvoiceProof\Http\Requests\UpdateInvoiceVerifierRequest;
use App\Modules\InvoiceProof\Models\InvoiceVerifier;
use App\Modules\InvoiceProof\Services\InvoiceVerifierService;
use Illuminate\Http\JsonResponse;

class InvoiceVerifierController extends Controller
{
    public function __construct(
        private readonly InvoiceVerifierService $invoiceVerifierService,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            InvoiceVerifierResponseData::collectionToArray($this->invoiceVerifierService->list()),
            'Invoice verifiers fetched successfully.'
        );
    }

    public function show(InvoiceVerifier $invoiceVerifier): JsonResponse
    {
        return ApiResponse::success(
            InvoiceVerifierResponseData::fromModel($this->invoiceVerifierService->show($invoiceVerifier))->toArray(),
            'Invoice verifier fetched successfully.'
        );
    }

    public function store(StoreInvoiceVerifierRequest $request): JsonResponse
    {
        $verifier = $this->invoiceVerifierService->create(InvoiceVerifierData::fromStoreRequest($request));

        return ApiResponse::created(
            InvoiceVerifierResponseData::fromModel($verifier->refresh())->toArray(),
            'Invoice verifier created successfully.'
        );
    }

    public function update(UpdateInvoiceVerifierRequest $request, InvoiceVerifier $invoiceVerifier): JsonResponse
    {
        $verifier = $this->invoiceVerifierService->update(
            $invoiceVerifier,
            InvoiceVerifierData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            InvoiceVerifierResponseData::fromModel($verifier)->toArray(),
            'Invoice verifier updated successfully.'
        );
    }

    public function destroy(InvoiceVerifier $invoiceVerifier): JsonResponse
    {
        $this->invoiceVerifierService->delete($invoiceVerifier);

        return ApiResponse::success(null, 'Invoice verifier removed successfully.');
    }

    public function sync(InvoiceVerifier $invoiceVerifier): JsonResponse
    {
        return ApiResponse::success(
            InvoiceVerifierResponseData::fromModel($this->invoiceVerifierService->resync($invoiceVerifier))->toArray(),
            'Invoice verifier sync queued.'
        );
    }
}
