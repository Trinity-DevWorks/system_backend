<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\PaymentMethod\DTOs\PaymentMethodData;
use App\Modules\PaymentMethod\DTOs\PaymentMethodResponseData;
use App\Modules\PaymentMethod\Http\Requests\StorePaymentMethodRequest;
use App\Modules\PaymentMethod\Http\Requests\UpdatePaymentMethodRequest;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentMethod\Services\PaymentMethodService;
use Illuminate\Http\JsonResponse;

class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentMethodService $paymentMethodService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            PaymentMethodResponseData::collectionToArray($this->paymentMethodService->list()),
            'Payment methods fetched successfully.'
        );
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $paymentMethod = $this->paymentMethodService->create(
            PaymentMethodData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            PaymentMethodResponseData::fromModel($paymentMethod)->toArray(),
            'Payment method created successfully.'
        );
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->loadMissing(['currency:id,code,name']);

        return ApiResponse::success(
            PaymentMethodResponseData::fromModel($paymentMethod)->toArray(),
            'Payment method fetched successfully.'
        );
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $updated = $this->paymentMethodService->update(
            $paymentMethod,
            PaymentMethodData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            PaymentMethodResponseData::fromModel($updated)->toArray(),
            'Payment method updated successfully.'
        );
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $this->paymentMethodService->delete($paymentMethod);

        return ApiResponse::success(null, 'Payment method deleted successfully.');
    }
}
