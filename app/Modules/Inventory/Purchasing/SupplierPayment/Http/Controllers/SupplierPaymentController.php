<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierPayment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Purchasing\SupplierPayment\DTOs\SupplierPaymentResponseData;
use App\Modules\Inventory\Purchasing\SupplierPayment\Http\Requests\StoreSupplierPaymentRequest;
use App\Modules\Inventory\Purchasing\SupplierPayment\Http\Requests\SyncSupplierPaymentAllocationsRequest;
use App\Modules\Inventory\Purchasing\SupplierPayment\Http\Requests\UpdateSupplierPaymentRequest;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\Inventory\Purchasing\SupplierPayment\Services\SupplierPaymentService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    public function __construct(
        private readonly SupplierPaymentService $supplierPaymentService,
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
            $this->supplierPaymentService->list($filters, ListPagination::perPage($request, 50)),
            fn (SupplierPayment $payment): array => SupplierPaymentResponseData::fromModel($payment, false),
            'Supplier payments fetched successfully.'
        );
    }

    public function openInvoices(Request $request): JsonResponse
    {
        $supplierId = trim((string) $request->query('supplier_id', ''));
        $currencyId = (int) $request->query('currency_id', 0);

        return ApiResponse::success(
            $this->supplierPaymentService->openInvoices($supplierId, $currencyId),
            'Open purchase invoices fetched successfully.'
        );
    }

    public function store(StoreSupplierPaymentRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $payment = $this->supplierPaymentService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::created(
            SupplierPaymentResponseData::fromModel($payment),
            'Supplier payment created successfully.'
        );
    }

    public function show(SupplierPayment $supplierPayment): JsonResponse
    {
        return ApiResponse::success(
            SupplierPaymentResponseData::fromModel($this->supplierPaymentService->find($supplierPayment->id)),
            'Supplier payment fetched successfully.'
        );
    }

    public function update(UpdateSupplierPaymentRequest $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $payment = $this->supplierPaymentService->updateHeader($supplierPayment, $request->validated());

        return ApiResponse::success(
            SupplierPaymentResponseData::fromModel($payment),
            'Supplier payment updated successfully.'
        );
    }

    public function destroy(SupplierPayment $supplierPayment): JsonResponse
    {
        $this->supplierPaymentService->delete($supplierPayment);

        return ApiResponse::success(null, 'Supplier payment deleted successfully.');
    }

    public function syncAllocations(SyncSupplierPaymentAllocationsRequest $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $payment = $this->supplierPaymentService->syncAllocations(
            $supplierPayment,
            $request->validated('allocations'),
        );

        return ApiResponse::success(
            [
                'allocations' => SupplierPaymentResponseData::allocationsToArray($payment->allocations),
                'supplier_payment' => SupplierPaymentResponseData::fromModel($payment),
            ],
            'Supplier payment allocations synced successfully.'
        );
    }

    public function post(Request $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $userId = $request->user()?->id;
        $payment = $this->supplierPaymentService->post(
            $supplierPayment,
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::success(
            SupplierPaymentResponseData::fromModel($payment),
            'Supplier payment posted successfully.'
        );
    }

    public function reverse(SupplierPayment $supplierPayment): JsonResponse
    {
        $payment = $this->supplierPaymentService->reverse($supplierPayment);

        return ApiResponse::success(
            SupplierPaymentResponseData::fromModel($payment),
            'Supplier payment reversed successfully.'
        );
    }
}
