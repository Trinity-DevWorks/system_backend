<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Stock\Services\PurchasingAlertService;
use App\Services\ModuleEntitlementService;
use App\Services\PermissionService;

/**
 * Operational snapshot. A section is omitted when its module is off, and a card
 * is omitted when the user cannot view the document it is built from.
 */
class OverviewService
{
    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly PeriodReportService $periods,
        private readonly AgingReportService $aging,
        private readonly StockValuationService $stock,
        private readonly PurchasingAlertService $alerts,
        private readonly ModuleEntitlementService $modules,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $asOf = $this->fiscal->today();
        $month = $this->fiscal->currentMonthToDate();
        $report = [
            'as_of' => $asOf,
            'currency_code' => (string) (Currency::getPrimary()?->code ?? ''),
        ];

        $sales = $this->visible('sales', 'sales_invoices');
        $purchases = $this->visible('purchasing', 'purchase_invoices');
        $inventory = $this->visible('inventory', 'stock');

        if ($sales) {
            $receivables = $this->aging->customers($asOf, null);
            $report['sales'] = [
                'today' => $this->periods->sales($asOf, $asOf, 'day', null)['totals']['net_sales'],
                'month_to_date' => $this->periods->sales($month['from'], $month['to'], 'month', null)['totals']['net_sales'],
                'open_receivables' => $receivables['totals']['total'],
            ];
            $report['aging']['receivables'] = $receivables['totals'];
        }

        if ($purchases) {
            $payables = $this->aging->suppliers($asOf, null);
            $report['purchases'] = [
                'today' => $this->periods->purchases($asOf, $asOf, 'day', null)['totals']['net_sales'],
                'month_to_date' => $this->periods->purchases($month['from'], $month['to'], 'month', null)['totals']['net_sales'],
                'open_payables' => $payables['totals']['total'],
            ];
            $report['aging']['payables'] = $payables['totals'];
        }

        if ($inventory) {
            $report['inventory'] = [
                'stock_value' => $this->stock->companyValue(),
                'low_stock_count' => $this->alerts->alertCount(),
            ];
        }

        return $report;
    }

    private function visible(string $module, string $resource): bool
    {
        if (! $this->moduleOn($module)) {
            return false;
        }

        $user = request()->user();

        return $user instanceof User && $this->permissions->userHas($resource, 'view', $user);
    }

    private function moduleOn(string $module): bool
    {
        $tenantId = tenant('id');

        return $tenantId !== null && $this->modules->tenantHas((string) $tenantId, $module);
    }
}
