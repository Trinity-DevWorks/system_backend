<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\DTOs\PurchaseDebitNoteResponseData;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Requests\ApplyPurchaseDebitNoteRequest;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Requests\StorePurchaseDebitNoteRequest;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Requests\SyncPurchaseDebitNoteLinesRequest;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Requests\UpdatePurchaseDebitNoteRequest;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Services\PurchaseDebitNoteService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseDebitNoteController extends Controller
{
    public function __construct(
        private readonly PurchaseDebitNoteService $purchaseDebitNoteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'supplier_id' => $request->string('supplier_id')->toString() ?: null,
            'purchase_invoice_id' => $request->string('purchase_invoice_id')->toString() ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->purchaseDebitNoteService->list($filters, ListPagination::perPage($request, 50)),
            fn (PurchaseDebitNote $note): array => PurchaseDebitNoteResponseData::fromModel($note, false),
            'Purchase debit notes fetched successfully.'
        );
    }

    public function invoiceLines(Request $request): JsonResponse
    {
        $invoiceId = trim((string) $request->query('purchase_invoice_id', ''));

        return ApiResponse::success(
            $this->purchaseDebitNoteService->invoiceLines($invoiceId),
            'Invoice lines fetched successfully.'
        );
    }

    public function store(StorePurchaseDebitNoteRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->purchaseDebitNoteService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::created(
            PurchaseDebitNoteResponseData::fromModel($note),
            'Purchase debit note created successfully.'
        );
    }

    public function show(PurchaseDebitNote $purchaseDebitNote): JsonResponse
    {
        $note = $this->purchaseDebitNoteService->find($purchaseDebitNote->id);

        return ApiResponse::success(
            PurchaseDebitNoteResponseData::fromModel($note, true, $this->purchaseDebitNoteService->applicableInvoices($note)),
            'Purchase debit note fetched successfully.'
        );
    }

    public function update(UpdatePurchaseDebitNoteRequest $request, PurchaseDebitNote $purchaseDebitNote): JsonResponse
    {
        $note = $this->purchaseDebitNoteService->updateHeader($purchaseDebitNote, $request->validated());

        return ApiResponse::success(
            PurchaseDebitNoteResponseData::fromModel($note),
            'Purchase debit note updated successfully.'
        );
    }

    public function destroy(PurchaseDebitNote $purchaseDebitNote): JsonResponse
    {
        $this->purchaseDebitNoteService->delete($purchaseDebitNote);

        return ApiResponse::success(null, 'Purchase debit note deleted successfully.');
    }

    public function syncLines(SyncPurchaseDebitNoteLinesRequest $request, PurchaseDebitNote $purchaseDebitNote): JsonResponse
    {
        $note = $this->purchaseDebitNoteService->syncLines($purchaseDebitNote, $request->validated('lines'));

        return ApiResponse::success(
            PurchaseDebitNoteResponseData::fromModel($note),
            'Purchase debit note lines saved successfully.'
        );
    }

    public function post(Request $request, PurchaseDebitNote $purchaseDebitNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->purchaseDebitNoteService->post($purchaseDebitNote, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(
            PurchaseDebitNoteResponseData::fromModel($note, true, $this->purchaseDebitNoteService->applicableInvoices($note)),
            'Purchase debit note posted successfully.'
        );
    }

    public function apply(ApplyPurchaseDebitNoteRequest $request, PurchaseDebitNote $purchaseDebitNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->purchaseDebitNoteService->apply(
            $purchaseDebitNote,
            $request->validated('allocations'),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::success(
            PurchaseDebitNoteResponseData::fromModel($note, true, $this->purchaseDebitNoteService->applicableInvoices($note)),
            'Debit applied successfully.'
        );
    }

    public function reverse(Request $request, PurchaseDebitNote $purchaseDebitNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->purchaseDebitNoteService->reverse($purchaseDebitNote, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(
            PurchaseDebitNoteResponseData::fromModel($note),
            'Purchase debit note reversed successfully.'
        );
    }
}
