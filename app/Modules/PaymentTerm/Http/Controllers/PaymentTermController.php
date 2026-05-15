<?php

declare(strict_types=1);

namespace App\Modules\PaymentTerm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\PaymentTerm\DTOs\PaymentTermData;
use App\Modules\PaymentTerm\DTOs\PaymentTermResponseData;
use App\Modules\PaymentTerm\Http\Requests\StorePaymentTermRequest;
use App\Modules\PaymentTerm\Http\Requests\UpdatePaymentTermRequest;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\PaymentTerm\Services\PaymentTermService;
use Illuminate\Http\JsonResponse;

class PaymentTermController extends Controller
{
    public function __construct(
        private readonly PaymentTermService $paymentTermService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            PaymentTermResponseData::collectionToArray($this->paymentTermService->list()),
            'Payment terms fetched successfully.'
        );
    }

    public function store(StorePaymentTermRequest $request): JsonResponse
    {
        $paymentTerm = $this->paymentTermService->create(
            PaymentTermData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            PaymentTermResponseData::fromModel($paymentTerm)->toArray(),
            'Payment term created successfully.'
        );
    }

    public function show(PaymentTerm $paymentTerm): JsonResponse
    {
        return ApiResponse::success(
            PaymentTermResponseData::fromModel($paymentTerm)->toArray(),
            'Payment term fetched successfully.'
        );
    }

    public function update(UpdatePaymentTermRequest $request, PaymentTerm $paymentTerm): JsonResponse
    {
        $updated = $this->paymentTermService->update(
            $paymentTerm,
            PaymentTermData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            PaymentTermResponseData::fromModel($updated)->toArray(),
            'Payment term updated successfully.'
        );
    }

    public function destroy(PaymentTerm $paymentTerm): JsonResponse
    {
        $this->paymentTermService->delete($paymentTerm);

        return ApiResponse::success(null, 'Payment term deleted successfully.');
    }
}
