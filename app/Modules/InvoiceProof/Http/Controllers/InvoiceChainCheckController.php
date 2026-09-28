<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\InvoiceProof\DTOs\InvoiceChainCheckResponseData;
use App\Modules\InvoiceProof\Models\InvoiceChainCheck;
use App\Modules\InvoiceProof\Services\InvoiceChainConsistencyService;
use Illuminate\Http\JsonResponse;

class InvoiceChainCheckController extends Controller
{
    public function __construct(
        private readonly InvoiceChainConsistencyService $invoiceChainConsistencyService,
    ) {}

    public function show(): JsonResponse
    {
        $this->invoiceChainConsistencyService->abortIfDisabled();

        return ApiResponse::success(
            $this->payload($this->invoiceChainConsistencyService->latest()),
            'Chain consistency check fetched successfully.'
        );
    }

    public function run(): JsonResponse
    {
        $this->invoiceChainConsistencyService->abortIfDisabled();

        return ApiResponse::success(
            $this->payload($this->invoiceChainConsistencyService->run()),
            'Chain consistency check completed.'
        );
    }

    /**
     * @return array{available: bool, check: array<string, mixed>|null}
     */
    private function payload(?InvoiceChainCheck $check): array
    {
        return [
            'available' => $this->invoiceChainConsistencyService->isAvailable(),
            'check' => $check !== null ? InvoiceChainCheckResponseData::fromModel($check)->toArray() : null,
        ];
    }
}
