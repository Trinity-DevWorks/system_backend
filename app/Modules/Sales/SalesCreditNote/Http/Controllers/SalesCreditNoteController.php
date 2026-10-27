<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\SalesCreditNote\DTOs\SalesCreditNoteResponseData;
use App\Modules\Sales\SalesCreditNote\Http\Requests\ApplySalesCreditNoteRequest;
use App\Modules\Sales\SalesCreditNote\Http\Requests\StoreSalesCreditNoteRequest;
use App\Modules\Sales\SalesCreditNote\Http\Requests\SyncSalesCreditNoteLinesRequest;
use App\Modules\Sales\SalesCreditNote\Http\Requests\UpdateSalesCreditNoteRequest;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesCreditNote\Services\SalesCreditNotePdfService;
use App\Modules\Sales\SalesCreditNote\Services\SalesCreditNoteService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesCreditNoteController extends Controller
{
    public function __construct(
        private readonly SalesCreditNoteService $salesCreditNoteService,
        private readonly SalesCreditNotePdfService $salesCreditNotePdfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'customer_id' => $request->string('customer_id')->toString() ?: null,
            'sales_invoice_id' => $request->string('sales_invoice_id')->toString() ?: null,
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

    public function invoiceLines(Request $request): JsonResponse
    {
        $invoiceId = trim((string) $request->query('sales_invoice_id', ''));

        return ApiResponse::success(
            $this->salesCreditNoteService->invoiceLines($invoiceId),
            'Invoice lines fetched successfully.'
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
        $note = $this->salesCreditNoteService->find($salesCreditNote->id);

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note, true, $this->salesCreditNoteService->applicableInvoices($note)),
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
            'Sales credit note lines saved successfully.'
        );
    }

    public function post(Request $request, SalesCreditNote $salesCreditNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->salesCreditNoteService->post($salesCreditNote, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note, true, $this->salesCreditNoteService->applicableInvoices($note)),
            'Sales credit note posted successfully.'
        );
    }

    public function apply(ApplySalesCreditNoteRequest $request, SalesCreditNote $salesCreditNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->salesCreditNoteService->apply(
            $salesCreditNote,
            $request->validated('allocations'),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note, true, $this->salesCreditNoteService->applicableInvoices($note)),
            'Credit applied successfully.'
        );
    }

    public function reverse(Request $request, SalesCreditNote $salesCreditNote): JsonResponse
    {
        $userId = $request->user()?->id;
        $note = $this->salesCreditNoteService->reverse($salesCreditNote, $userId !== null ? (string) $userId : null);

        return ApiResponse::success(
            SalesCreditNoteResponseData::fromModel($note),
            'Sales credit note reversed successfully.'
        );
    }

    public function pdf(Request $request, SalesCreditNote $salesCreditNote): Response
    {
        $pdf = $this->salesCreditNotePdfService->render(
            $salesCreditNote,
            $request->query('locale')
        );

        return $pdf->download($this->salesCreditNotePdfService->downloadFilename($salesCreditNote));
    }
}
