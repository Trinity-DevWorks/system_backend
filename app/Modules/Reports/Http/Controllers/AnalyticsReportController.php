<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Reports\Http\Requests\AnalyticsReportRequest;
use App\Modules\Reports\Services\AccountingAnalyticsService;
use App\Modules\Reports\Services\SalesProfitService;
use App\Modules\Reports\Services\StockAnalyticsService;
use App\Modules\Reports\Support\AnalyticsReport;
use App\Support\Export\TabularExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Analytics reports. Each route sets a "report" default; JSON and /export share the same data.
 */
class AnalyticsReportController extends Controller
{
    /** report => [lang title key, export filename] */
    private const REPORTS = [
        'sales-analysis' => ['sales_analysis_title', 'sales-analysis'],
        'sales-profitability' => ['sales_profitability_title', 'sales-profitability'],
        'lot-expiry' => ['lot_expiry_title', 'lot-expiry'],
        'dead-stock' => ['dead_stock_title', 'dead-stock'],
        'item-movement' => ['item_movement_title', 'item-movement'],
        'cash-flow' => ['cash_flow_title', 'cash-flow'],
        'general-ledger' => ['general_ledger_title', 'general-ledger'],
    ];

    public function __construct(
        private readonly SalesProfitService $sales,
        private readonly StockAnalyticsService $stock,
        private readonly AccountingAnalyticsService $accounting,
        private readonly TabularExportService $tabularExportService,
    ) {}

    public function show(AnalyticsReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->data($request, true), 'Report generated successfully.');
    }

    public function export(AnalyticsReportRequest $request): Response
    {
        app()->setLocale((string) ($request->validated('locale') ?? 'en'));
        [$title, $file] = self::REPORTS[$this->name($request)];

        // Exports keep search and sort but always include every row (no pagination).
        return $this->tabularExportService->download(
            AnalyticsReport::document($this->data($request, false), $title, $file),
            (string) $request->validated('format'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function data(AnalyticsReportRequest $request, bool $paginate): array
    {
        $list = [
            'search' => $request->textFilter('search'),
            'sort' => $request->textFilter('sort'),
            'dir' => $request->textFilter('dir'),
            'page' => $paginate ? $request->intFilter('page') : null,
            'per_page' => $paginate ? $request->intFilter('per_page') : null,
        ];
        $from = $request->textFilter('from');
        $to = $request->textFilter('to');
        $branch = $request->intFilter('branch_id');
        $warehouse = $request->intFilter('warehouse_id');

        return match ($this->name($request)) {
            'sales-analysis' => $this->sales->analysis($from, $to, $request->textFilter('group_by'), $branch, $list),
            'sales-profitability' => $this->sales->profitability($from, $to, $branch, $list),
            'lot-expiry' => $this->stock->lotExpiry($request->intFilter('days'), $request->boolFilter('include_expired'), $warehouse, $list),
            'dead-stock' => $this->stock->deadStock($request->intFilter('days'), $warehouse, $list),
            'item-movement' => $this->itemMovement($request, $from, $to, $warehouse),
            'cash-flow' => $this->accounting->cashFlow($from, $to, $branch),
            'general-ledger' => $this->accounting->generalLedger($from, $to, $branch, $request->textFilter('account_from'), $request->textFilter('account_to'), $list),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function itemMovement(AnalyticsReportRequest $request, ?string $from, ?string $to, ?int $warehouse): array
    {
        $itemId = $request->textFilter('item_id');
        if ($itemId === null) {
            abort(422, 'Choose an item.', ['X-Error-Code' => 'REPORT_ITEM_REQUIRED']);
        }

        return $this->stock->itemMovement($itemId, $warehouse, $from, $to);
    }

    private function name(AnalyticsReportRequest $request): string
    {
        $name = (string) $request->route('report');
        if (! isset(self::REPORTS[$name])) {
            abort(404);
        }

        return $name;
    }
}
