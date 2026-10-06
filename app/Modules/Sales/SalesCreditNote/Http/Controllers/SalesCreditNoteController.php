<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\SalesCreditNote\DTOs\SalesCreditNoteResponseData;
use App\Modules\Sales\SalesCreditNote\Http\Requests\StoreSalesCreditNoteRequest;
use App\Modules\Sales\SalesCreditNote\Http\Requests\SyncSalesCreditNoteLinesRequest;
use App\Modules\Sales\SalesCreditNote\Http\Requests\UpdateSalesCreditNoteRequest;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesCreditNote\Services\SalesCreditNoteService;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesCreditNoteController extends Controller
{
    public function __construct(
        private readonly SalesCreditNoteService $salesCreditNoteService,
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
            $this->salesCreditNoteService->list($filters, ListPagination::perPage($request, 50)),
            fn (SalesCreditNote $note): array => SalesCreditNoteResponseData::fromModel($note, false),
            'Sales credit notes fetched successfully.'
        );
    }

    public function openInvoices(Request $request): JsonResponse
    {
        $customerId = trim((string) $request->query('customer_id', ''));

        return ApiResponse::success(
            $this->salesCreditNoteService->openInvoices($customerId !== '' ? $customerId : null),
            'Creditable sales invoices fetched successfully.'
        );
    }

    public function sourceLines(SalesInvoice $salesInvoice): JsonResponse
    {
        return ApiResponse::success(
            $this->salesCreditNoteService->sourceLines($salesInvoice),
            'Creditable invoice lines fetched successfully.'
        );
    }

    public function store(StoreSalesCreditNoteRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->salesCreditNoteService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::created(
            SalesCreditNoteResponseData::fromModel($note),
            'Sales credit note created successfully.'
        );
    }

    public function show(SalesCreditNote $salesCreditNote): JsonResponse
    {
        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($this->salesCreditNoteService->find($salesCreditNote->id)),
            'Sales credit note fetched successfully.'
        );
    }

    public function update(UpdateSalesCreditNoteRequest $request, SalesCreditNote $salesCreditNote): JsonResponse
    {
        $note = $this->salesCreditNoteService->updateHeader($salesCreditNote, $request->validated());

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note),
            'Sales credit note updated successfully.'
        );
    }

    public function destroy(SalesCreditNote $salesCreditNote): JsonResponse
    {
        $this->salesCreditNoteService->delete($salesCreditNote);

        return ApiResponse::success(null, 'Sales credit note deleted successfully.');
    }

    public function syncLines(SyncSalesCreditNoteLinesRequest $request, SalesCreditNote $salesCreditNote): JsonResponse
    {
        $note = $this->salesCreditNoteService->syncLines($salesCreditNote, $request->validated('lines'));

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note),
            'Sales credit note lines synced successfully.'
        );
    }

    public function post(Request $request, SalesCreditNote $salesCreditNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->salesCreditNoteService->post(
            $salesCreditNote,
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note),
            'Sales credit note posted successfully.'
        );
    }

    public function reverse(Request $request, SalesCreditNote $salesCreditNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->salesCreditNoteService->reverse(
            $salesCreditNote,
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note),
            'Sales credit note reversed successfully.'
        );
    }
}
