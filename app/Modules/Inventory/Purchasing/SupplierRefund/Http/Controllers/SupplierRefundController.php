<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierRefund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Purchasing\SupplierRefund\DTOs\SupplierRefundResponseData;
use App\Modules\Inventory\Purchasing\SupplierRefund\Http\Requests\StoreSupplierRefundRequest;
use App\Modules\Inventory\Purchasing\SupplierRefund\Http\Requests\SyncSupplierRefundAllocationsRequest;
use App\Modules\Inventory\Purchasing\SupplierRefund\Http\Requests\UpdateSupplierRefundRequest;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefund;
use App\Modules\Inventory\Purchasing\SupplierRefund\Services\SupplierRefundService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierRefundController extends Controller
{
    public function __construct(
        private readonly SupplierRefundService $supplierRefundService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->supplierRefundService->list([
                'status' => $request->string('status')->toString() ?: null,
                'supplier_id' => $request->string('supplier_id')->toString() ?: null,
                'search' => ListPagination::search($request),
                'from' => $request->string('from')->toString() ?: null,
                'to' => $request->string('to')->toString() ?: null,
            ], ListPagination::perPage($request, 50)),
            fn (SupplierRefund $refund): array => SupplierRefundResponseData::fromModel($refund, false),
            'Supplier refunds fetched successfully.'
        );
    }

    public function openDebitNotes(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->supplierRefundService->openDebitNotes(
                trim((string) $request->query('supplier_id', '')),
                (int) $request->query('currency_id', 0),
            ),
            'Open debit notes fetched successfully.'
        );
    }

    public function store(StoreSupplierRefundRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $refund = $this->supplierRefundService->create($request->validated(), $userId !== null ? (string) $userId : null);

        return ApiResponse::created(SupplierRefundResponseData::fromModel($refund), 'Supplier refund created successfully.');
    }

    public function show(SupplierRefund $supplierRefund): JsonResponse
    {
        return ApiResponse::success(
            SupplierRefundResponseData::fromModel($this->supplierRefundService->find($supplierRefund->id)),
            'Supplier refund fetched successfully.'
        );
    }

    public function update(UpdateSupplierRefundRequest $request, SupplierRefund $supplierRefund): JsonResponse
    {
        $refund = $this->supplierRefundService->updateHeader($supplierRefund, $request->validated());

        return ApiResponse::success(SupplierRefundResponseData::fromModel($refund), 'Supplier refund updated successfully.');
    }

    public function destroy(SupplierRefund $supplierRefund): JsonResponse
    {
        $this->supplierRefundService->delete($supplierRefund);

        return ApiResponse::success(null, 'Supplier refund deleted successfully.');
    }

    public function syncAllocations(SyncSupplierRefundAllocationsRequest $request, SupplierRefund $supplierRefund): JsonResponse
    {
        $refund = $this->supplierRefundService->syncAllocations($supplierRefund, $request->validated('allocations'));

        return ApiResponse::success(
            [
                'allocations' => SupplierRefundResponseData::allocationsToArray($refund->allocations),
                'supplier_refund' => SupplierRefundResponseData::fromModel($refund),
            ],
            'Supplier refund allocations synced successfully.'
        );
    }

    public function post(Request $request, SupplierRefund $supplierRefund): JsonResponse
    {
        $userId = $request->user()?->id;
        $refund = $this->supplierRefundService->post($supplierRefund, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(SupplierRefundResponseData::fromModel($refund), 'Supplier refund posted successfully.');
    }

    public function reverse(Request $request, SupplierRefund $supplierRefund): JsonResponse
    {
        $userId = $request->user()?->id;
        $refund = $this->supplierRefundService->reverse($supplierRefund, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(SupplierRefundResponseData::fromModel($refund), 'Supplier refund reversed successfully.');
    }
}
