<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\SalesQuotation\DTOs\SalesQuotationLineResponseData;
use App\Modules\Sales\SalesQuotation\DTOs\SalesQuotationResponseData;
use App\Modules\Sales\SalesQuotation\Http\Requests\StoreSalesQuotationRequest;
use App\Modules\Sales\SalesQuotation\Http\Requests\SyncSalesQuotationLinesRequest;
use App\Modules\Sales\SalesQuotation\Http\Requests\UpdateSalesQuotationRequest;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Sales\SalesQuotation\Services\SalesQuotationPdfService;
use App\Modules\Sales\SalesQuotation\Services\SalesQuotationService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesQuotationController extends Controller
{
    public function __construct(
        private readonly SalesQuotationService $salesQuotationService,
        private readonly SalesQuotationPdfService $salesQuotationPdfService,
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
            $this->salesQuotationService->list($filters, ListPagination::perPage($request, 50)),
            fn (SalesQuotation $quotation): array => SalesQuotationResponseData::fromModel($quotation, false),
            'Sales quotations fetched successfully.'
        );
    }

    public function store(StoreSalesQuotationRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $quotation = $this->salesQuotationService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null,
        );

        return ApiResponse::created(
            SalesQuotationResponseData::fromModel($quotation),
            'Sales quotation created successfully.'
        );
    }

    public function show(SalesQuotation $salesQuotation): JsonResponse
    {
        return ApiResponse::success(
            SalesQuotationResponseData::fromModel($this->salesQuotationService->find($salesQuotation->id)),
            'Sales quotation fetched successfully.'
        );
    }

    public function update(UpdateSalesQuotationRequest $request, SalesQuotation $salesQuotation): JsonResponse
    {
        $quotation = $this->salesQuotationService->updateHeader($salesQuotation, $request->validated());

        return ApiResponse::success(
            SalesQuotationResponseData::fromModel($quotation),
            'Sales quotation updated successfully.'
        );
    }

    public function destroy(SalesQuotation $salesQuotation): JsonResponse
    {
        $this->salesQuotationService->delete($salesQuotation);

        return ApiResponse::success(null, 'Sales quotation deleted successfully.');
    }

    public function syncLines(SyncSalesQuotationLinesRequest $request, SalesQuotation $salesQuotation): JsonResponse
    {
        $lines = $this->salesQuotationService->syncLines($salesQuotation, $request->validated('lines'));
        $quotation = $this->salesQuotationService->find($salesQuotation->id);

        return ApiResponse::success(
            [
                'lines' => SalesQuotationLineResponseData::collectionToArray($lines),
                'quotation' => SalesQuotationResponseData::fromModel($quotation),
            ],
            'Sales quotation lines synced successfully.'
        );
    }

    public function send(Request $request, SalesQuotation $salesQuotation): JsonResponse
    {
        $userId = $request->user()?->id;

        return ApiResponse::success(
            SalesQuotationResponseData::fromModel(
                $this->salesQuotationService->send($salesQuotation, $userId !== null ? (string) $userId : null),
            ),
            'Sales quotation sent successfully.'
        );
    }

    public function accept(Request $request, SalesQuotation $salesQuotation): JsonResponse
    {
        $userId = $request->user()?->id;

        return ApiResponse::success(
            SalesQuotationResponseData::fromModel(
                $this->salesQuotationService->accept($salesQuotation, $userId !== null ? (string) $userId : null),
            ),
            'Sales quotation accepted successfully.'
        );
    }

    public function reject(Request $request, SalesQuotation $salesQuotation): JsonResponse
    {
        $userId = $request->user()?->id;

        return ApiResponse::success(
            SalesQuotationResponseData::fromModel(
                $this->salesQuotationService->reject($salesQuotation, $userId !== null ? (string) $userId : null),
            ),
            'Sales quotation rejected successfully.'
        );
    }

    public function expire(SalesQuotation $salesQuotation): JsonResponse
    {
        return ApiResponse::success(
            SalesQuotationResponseData::fromModel($this->salesQuotationService->expire($salesQuotation)),
            'Sales quotation expired successfully.'
        );
    }

    public function convert(Request $request, SalesQuotation $salesQuotation): JsonResponse
    {
        $userId = $request->user()?->id;

        return ApiResponse::success(
            SalesQuotationResponseData::fromModel($this->salesQuotationService->convert(
                $salesQuotation,
                $userId !== null ? (string) $userId : null,
            )),
            'Sales quotation converted successfully.'
        );
    }

    public function pdf(Request $request, SalesQuotation $salesQuotation): Response
    {
        $pdf = $this->salesQuotationPdfService->render(
            $salesQuotation,
            $request->query('locale')
        );

        return $pdf->download($this->salesQuotationPdfService->downloadFilename($salesQuotation));
    }
}
