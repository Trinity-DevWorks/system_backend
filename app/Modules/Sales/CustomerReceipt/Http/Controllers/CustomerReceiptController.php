<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerReceipt\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\CustomerReceipt\DTOs\CustomerReceiptResponseData;
use App\Modules\Sales\CustomerReceipt\Http\Requests\StoreCustomerReceiptRequest;
use App\Modules\Sales\CustomerReceipt\Http\Requests\SyncCustomerReceiptAllocationsRequest;
use App\Modules\Sales\CustomerReceipt\Http\Requests\UpdateCustomerReceiptRequest;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerReceiptController extends Controller
{
    public function __construct(
        private readonly CustomerReceiptService $customerReceiptService,
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
            $this->customerReceiptService->list($filters, ListPagination::perPage($request, 50)),
            fn (CustomerReceipt $receipt): array => CustomerReceiptResponseData::fromModel($receipt, false),
            'Customer receipts fetched successfully.'
        );
    }

    public function openInvoices(Request $request): JsonResponse
    {
        $customerId = trim((string) $request->query('customer_id', ''));
        $currencyId = (int) $request->query('currency_id', 0);

        return ApiResponse::success(
            $this->customerReceiptService->openInvoices($customerId, $currencyId),
            'Open sales invoices fetched successfully.'
        );
    }

    public function store(StoreCustomerReceiptRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $receipt = $this->customerReceiptService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::created(
            CustomerReceiptResponseData::fromModel($receipt),
            'Customer receipt created successfully.'
        );
    }

    public function show(CustomerReceipt $customerReceipt): JsonResponse
    {
        return ApiResponse::success(
            CustomerReceiptResponseData::fromModel($this->customerReceiptService->find($customerReceipt->id)),
            'Customer receipt fetched successfully.'
        );
    }

    public function update(UpdateCustomerReceiptRequest $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $receipt = $this->customerReceiptService->updateHeader($customerReceipt, $request->validated());

        return ApiResponse::success(
            CustomerReceiptResponseData::fromModel($receipt),
            'Customer receipt updated successfully.'
        );
    }

    public function destroy(CustomerReceipt $customerReceipt): JsonResponse
    {
        $this->customerReceiptService->delete($customerReceipt);

        return ApiResponse::success(null, 'Customer receipt deleted successfully.');
    }

    public function syncAllocations(SyncCustomerReceiptAllocationsRequest $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $receipt = $this->customerReceiptService->syncAllocations(
            $customerReceipt,
            $request->validated('allocations'),
        );

        return ApiResponse::success(
            [
                'allocations' => CustomerReceiptResponseData::allocationsToArray($receipt->allocations),
                'customer_receipt' => CustomerReceiptResponseData::fromModel($receipt),
            ],
            'Customer receipt allocations synced successfully.'
        );
    }

    public function post(Request $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $userId = $request->user()?->id;
        $receipt = $this->customerReceiptService->post(
            $customerReceipt,
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::success(
            CustomerReceiptResponseData::fromModel($receipt),
            'Customer receipt posted successfully.'
        );
    }

    public function reverse(CustomerReceipt $customerReceipt): JsonResponse
    {
        $receipt = $this->customerReceiptService->reverse($customerReceipt);

        return ApiResponse::success(
            CustomerReceiptResponseData::fromModel($receipt),
            'Customer receipt reversed successfully.'
        );
    }
}
