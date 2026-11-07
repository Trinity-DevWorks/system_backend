<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerRefund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\CustomerRefund\DTOs\CustomerRefundResponseData;
use App\Modules\Sales\CustomerRefund\Http\Requests\StoreCustomerRefundRequest;
use App\Modules\Sales\CustomerRefund\Http\Requests\SyncCustomerRefundAllocationsRequest;
use App\Modules\Sales\CustomerRefund\Http\Requests\UpdateCustomerRefundRequest;
use App\Modules\Sales\CustomerRefund\Models\CustomerRefund;
use App\Modules\Sales\CustomerRefund\Services\CustomerRefundPdfService;
use App\Modules\Sales\CustomerRefund\Services\CustomerRefundService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerRefundController extends Controller
{
    public function __construct(
        private readonly CustomerRefundService $customerRefundService,
        private readonly CustomerRefundPdfService $customerRefundPdfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->customerRefundService->list([
                'status' => $request->string('status')->toString() ?: null,
                'customer_id' => $request->string('customer_id')->toString() ?: null,
                'search' => ListPagination::search($request),
                'from' => $request->string('from')->toString() ?: null,
                'to' => $request->string('to')->toString() ?: null,
            ], ListPagination::perPage($request, 50)),
            fn (CustomerRefund $refund): array => CustomerRefundResponseData::fromModel($refund, false),
            'Customer refunds fetched successfully.'
        );
    }

    public function openCreditNotes(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->customerRefundService->openCreditNotes(
                trim((string) $request->query('customer_id', '')),
                (int) $request->query('currency_id', 0),
            ),
            'Open credit notes fetched successfully.'
        );
    }

    public function store(StoreCustomerRefundRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $refund = $this->customerRefundService->create($request->validated(), $userId !== null ? (string) $userId : null);

        return ApiResponse::created(CustomerRefundResponseData::fromModel($refund), 'Customer refund created successfully.');
    }

    public function show(CustomerRefund $customerRefund): JsonResponse
    {
        return ApiResponse::success(
            CustomerRefundResponseData::fromModel($this->customerRefundService->find($customerRefund->id)),
            'Customer refund fetched successfully.'
        );
    }

    public function update(UpdateCustomerRefundRequest $request, CustomerRefund $customerRefund): JsonResponse
    {
        $refund = $this->customerRefundService->updateHeader($customerRefund, $request->validated());

        return ApiResponse::success(CustomerRefundResponseData::fromModel($refund), 'Customer refund updated successfully.');
    }

    public function destroy(CustomerRefund $customerRefund): JsonResponse
    {
        $this->customerRefundService->delete($customerRefund);

        return ApiResponse::success(null, 'Customer refund deleted successfully.');
    }

    public function syncAllocations(SyncCustomerRefundAllocationsRequest $request, CustomerRefund $customerRefund): JsonResponse
    {
        $refund = $this->customerRefundService->syncAllocations($customerRefund, $request->validated('allocations'));

        return ApiResponse::success(
            [
                'allocations' => CustomerRefundResponseData::allocationsToArray($refund->allocations),
                'customer_refund' => CustomerRefundResponseData::fromModel($refund),
            ],
            'Customer refund allocations synced successfully.'
        );
    }

    public function post(Request $request, CustomerRefund $customerRefund): JsonResponse
    {
        $userId = $request->user()?->id;
        $refund = $this->customerRefundService->post($customerRefund, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(CustomerRefundResponseData::fromModel($refund), 'Customer refund posted successfully.');
    }

    public function reverse(Request $request, CustomerRefund $customerRefund): JsonResponse
    {
        $userId = $request->user()?->id;
        $refund = $this->customerRefundService->reverse($customerRefund, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(CustomerRefundResponseData::fromModel($refund), 'Customer refund reversed successfully.');
    }

    public function pdf(Request $request, CustomerRefund $customerRefund): Response
    {
        $pdf = $this->customerRefundPdfService->render(
            $customerRefund,
            $request->query('locale')
        );

        return $pdf->download($this->customerRefundPdfService->downloadFilename($customerRefund));
    }
}
