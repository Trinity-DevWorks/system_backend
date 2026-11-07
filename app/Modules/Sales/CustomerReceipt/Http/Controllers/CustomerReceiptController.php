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
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptPdfService;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerReceiptController extends Controller
{
    public function __construct(
        private readonly CustomerReceiptService $customerReceiptService,
        private readonly CustomerReceiptPdfService $customerReceiptPdfService,
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

    /**
     * Credit left on a posted receipt, applied to open invoices: rows of sales_invoice_id + amount (receipt currency).
     */
    public function applyCredit(Request $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.sales_invoice_id' => ['required', 'uuid', 'exists:sales_invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
        ]);
        $userId = $request->user()?->id;
        $receipt = $this->customerReceiptService->applyCredit(
            $customerReceipt,
            $data['allocations'],
            $userId !== null ? (string) $userId : null,
            isset($data['date']) ? (string) $data['date'] : null,
        );

        return ApiResponse::success(CustomerReceiptResponseData::fromModel($receipt), 'Customer credit applied successfully.');
    }

    /**
     * The customer's credit (posted receipts with money left), with its value in `currency_id` when given.
     */
    public function availableCredit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
        ]);

        return ApiResponse::success(
            $this->customerReceiptService->availableCredit(
                (string) $data['customer_id'],
                isset($data['currency_id']) ? (int) $data['currency_id'] : null,
            ),
            'Customer credit fetched successfully.'
        );
    }

    public function reverse(Request $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $userId = $request->user()?->id;
        $receipt = $this->customerReceiptService->reverse(
            $customerReceipt,
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::success(
            CustomerReceiptResponseData::fromModel($receipt),
            'Customer receipt reversed successfully.'
        );
    }

    public function pdf(Request $request, CustomerReceipt $customerReceipt): Response
    {
        $pdf = $this->customerReceiptPdfService->render(
            $customerReceipt,
            $request->query('locale'),
            is_string($request->query('template')) ? $request->query('template') : null,
        );

        return $pdf->download($this->customerReceiptPdfService->downloadFilename($customerReceipt));
    }
}
