<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Reports\Http\Requests\AgingReportRequest;
use App\Modules\Reports\Http\Requests\PeriodReportRequest;
use App\Modules\Reports\Http\Requests\StockValuationRequest;
use App\Modules\Reports\Services\AgingReportService;
use App\Modules\Reports\Services\PeriodReportService;
use App\Modules\Reports\Services\StockValuationService;
use App\Modules\Reports\Support\ReportDocuments;
use App\Support\Export\TabularDocument;
use App\Support\Export\TabularExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly PeriodReportService $periods,
        private readonly AgingReportService $aging,
        private readonly StockValuationService $stock,
        private readonly TabularExportService $tabularExportService,
    ) {}

    public function salesByPeriod(PeriodReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->salesData($request), 'Sales by period fetched successfully.');
    }

    public function exportSalesByPeriod(PeriodReportRequest $request): Response
    {
        return $this->export($request, fn (): TabularDocument => ReportDocuments::period($this->salesData($request), 'sales'));
    }

    public function purchasesByPeriod(PeriodReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->purchasesData($request), 'Purchases by period fetched successfully.');
    }

    public function exportPurchasesByPeriod(PeriodReportRequest $request): Response
    {
        return $this->export($request, fn (): TabularDocument => ReportDocuments::period($this->purchasesData($request), 'purchases'));
    }

    public function customerAging(AgingReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->customerAgingData($request), 'Customer aging fetched successfully.');
    }

    public function exportCustomerAging(AgingReportRequest $request): Response
    {
        return $this->export($request, fn (): TabularDocument => ReportDocuments::aging($this->customerAgingData($request), 'customers'));
    }

    public function supplierAging(AgingReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->supplierAgingData($request), 'Supplier aging fetched successfully.');
    }

    public function exportSupplierAging(AgingReportRequest $request): Response
    {
        return $this->export($request, fn (): TabularDocument => ReportDocuments::aging($this->supplierAgingData($request), 'suppliers'));
    }

    public function stockValuation(StockValuationRequest $request): JsonResponse
    {
        return ApiResponse::success($this->stockData($request), 'Stock valuation fetched successfully.');
    }

    public function exportStockValuation(StockValuationRequest $request): Response
    {
        return $this->export($request, fn (): TabularDocument => ReportDocuments::stock($this->stockData($request)));
    }

    /**
     * @return array<string, mixed>
     */
    private function salesData(PeriodReportRequest $request): array
    {
        return $this->periods->sales($request->dateFrom(), $request->dateTo(), $request->groupBy(), $request->branchId());
    }

    /**
     * @return array<string, mixed>
     */
    private function purchasesData(PeriodReportRequest $request): array
    {
        return $this->periods->purchases($request->dateFrom(), $request->dateTo(), $request->groupBy(), $request->branchId());
    }

    /**
     * @return array<string, mixed>
     */
    private function customerAgingData(AgingReportRequest $request): array
    {
        return $this->aging->customers($request->asOf(), $request->branchId(), $request->customerId());
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierAgingData(AgingReportRequest $request): array
    {
        return $this->aging->suppliers($request->asOf(), $request->branchId(), $request->supplierId());
    }

    /**
     * @return array<string, mixed>
     */
    private function stockData(StockValuationRequest $request): array
    {
        return $this->stock->report($request->warehouseId(), $request->categoryId(), $request->includeZero());
    }

    /**
     * @param  callable(): TabularDocument  $document
     */
    private function export(PeriodReportRequest|AgingReportRequest|StockValuationRequest $request, callable $document): Response
    {
        app()->setLocale((string) ($request->validated('locale') ?? 'en'));

        return $this->tabularExportService->download($document(), (string) $request->validated('format'));
    }
}
