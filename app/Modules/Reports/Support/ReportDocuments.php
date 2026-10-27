<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Branch\Models\Branch;
use App\Modules\Category\Models\Category;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Export\TabularDocument;

final class ReportDocuments
{
    /**
     * @param  array<string, mixed>  $report
     */
    public static function period(array $report, string $kind): TabularDocument
    {
        $symbol = self::primarySymbol();
        $sales = $kind === 'sales';
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                TenantDisplayFormat::date((string) $row['period']) ?: (string) $row['period'],
                (string) $row['document_count'],
                self::money($row['gross'], $symbol),
                self::money($row['tax'], $symbol),
                self::money($row['net'], $symbol),
                self::money($row['credit_note_total'], $symbol),
                self::money($row['net_sales'], $symbol),
            ];
        }
        $totals = $report['totals'];
        $rows[] = [
            '',
            (string) $totals['document_count'],
            self::money($totals['gross'], $symbol),
            self::money($totals['tax'], $symbol),
            self::money($totals['net'], $symbol),
            self::money($totals['credit_note_total'], $symbol),
            self::money($totals['net_sales'], $symbol),
        ];
        $rows[array_key_last($rows)][0] = __('reports.totals');

        $group = (string) ($report['group_by'] ?? 'month');

        return new TabularDocument(
            title: $sales ? __('reports.sales_by_period_title') : __('reports.purchases_by_period_title'),
            filename: $sales ? 'sales-by-period' : 'purchases-by-period',
            headings: [
                __('reports.col_period'),
                __('reports.col_documents'),
                __('reports.col_gross'),
                __('reports.col_tax'),
                __('reports.col_net'),
                $sales ? __('reports.col_credit_notes') : __('reports.col_debit_notes'),
                $sales ? __('reports.col_net_sales') : __('reports.col_net_purchases'),
            ],
            rows: $rows,
            filters: [
                ...self::headerFilters(),
                ...self::dateFilters($report['from'] ?? null, $report['to'] ?? null),
                __('reports.group_by').': '.__('reports.group_'.$group),
                ...self::branchFilter($report),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function aging(array $report, string $kind): TabularDocument
    {
        $symbol = self::primarySymbol();
        $customers = $kind === 'customers';
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = self::agingCells($row, $symbol);
        }
        $rows[] = self::agingCells(['code' => '', 'name' => __('reports.totals'), ...$report['totals']], $symbol);
        foreach ($report['invoices'] ?? [] as $index => $invoice) {
            if ($index === 0) {
                $rows[] = array_fill(0, 8, '');
                $rows[] = [__('reports.open_invoices'), '', '', '', '', '', '', ''];
            }
            $rows[] = [
                (string) ($invoice['number'] ?? ''),
                TenantDisplayFormat::date((string) ($invoice['invoice_date'] ?? '')) ?: (string) ($invoice['invoice_date'] ?? ''),
                TenantDisplayFormat::date((string) ($invoice['due_on'] ?? '')) ?: (string) ($invoice['due_on'] ?? ''),
                __('reports.bucket_'.(string) ($invoice['bucket'] ?? '')),
                '',
                '',
                '',
                self::money($invoice['net_to_pay'] ?? '0', $symbol),
            ];
        }

        return new TabularDocument(
            title: $customers ? __('reports.customer_aging_title') : __('reports.supplier_aging_title'),
            filename: $customers ? 'customer-aging' : 'supplier-aging',
            headings: [
                __('reports.col_code'),
                $customers ? __('reports.col_customer') : __('reports.col_supplier'),
                __('reports.bucket_current'),
                __('reports.bucket_1_30'),
                __('reports.bucket_31_60'),
                __('reports.bucket_61_90'),
                __('reports.bucket_90_plus'),
                __('reports.col_total'),
            ],
            rows: $rows,
            filters: [
                ...self::headerFilters(),
                __('reports.as_of').': '.(TenantDisplayFormat::date((string) $report['as_of']) ?: (string) $report['as_of']),
                ...self::branchFilter($report),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function stock(array $report): TabularDocument
    {
        $symbol = self::primarySymbol();
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                (string) $row['item_code'],
                (string) $row['item_name'],
                (string) $row['warehouse_name'],
                TenantDisplayFormat::quantity($row['quantity']),
                self::money($row['unit_cost'], $symbol),
                self::money($row['inventory_value'], $symbol),
            ];
        }
        $totals = $report['totals'];
        $rows[] = [
            '',
            __('reports.totals'),
            (string) $totals['lines'],
            TenantDisplayFormat::quantity($totals['quantity']),
            '',
            self::money($totals['inventory_value'], $symbol),
        ];

        $filters = [
            ...self::headerFilters(),
            __('reports.as_of_today'),
            __('reports.as_of').': '.(TenantDisplayFormat::date((string) $report['as_of']) ?: (string) $report['as_of']),
        ];
        if (! empty($report['warehouse_id'])) {
            $name = Warehouse::query()->whereKey((int) $report['warehouse_id'])->value('name');
            if ($name) {
                $filters[] = __('reports.warehouse').': '.$name;
            }
        }
        if (! empty($report['category_id'])) {
            $name = Category::query()->whereKey((int) $report['category_id'])->value('name');
            if ($name) {
                $filters[] = __('reports.category').': '.$name;
            }
        }
        $summaries = [];
        if (isset($report['ledger_check']) && is_array($report['ledger_check'])) {
            $check = $report['ledger_check'];
            $summaries[] = [
                'heading' => __('reports.ledger_check'),
                'lines' => [
                    ['label' => __('reports.ledger'), 'value' => self::money($check['ledger'] ?? '0', $symbol)],
                    ['label' => __('reports.stock'), 'value' => self::money($check['stock'] ?? '0', $symbol)],
                    ['label' => __('reports.difference'), 'value' => self::money($check['difference'] ?? '0', $symbol)],
                ],
            ];
        }

        return new TabularDocument(
            title: __('reports.stock_valuation_title'),
            filename: 'stock-valuation',
            headings: [
                __('reports.col_item_code'),
                __('reports.col_item'),
                __('reports.col_warehouse'),
                __('reports.col_quantity'),
                __('reports.col_unit_cost'),
                __('reports.col_value'),
            ],
            rows: $rows,
            filters: $filters,
            summaries: $summaries,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function agingCells(array $row, string $symbol): array
    {
        return [
            (string) ($row['code'] ?? ''),
            (string) ($row['name'] ?? ''),
            self::money($row['current'] ?? '0', $symbol),
            self::money($row['1_30'] ?? '0', $symbol),
            self::money($row['31_60'] ?? '0', $symbol),
            self::money($row['61_90'] ?? '0', $symbol),
            self::money($row['90_plus'] ?? '0', $symbol),
            self::money($row['total'] ?? '0', $symbol),
        ];
    }

    /**
     * @return list<string>
     */
    private static function headerFilters(): array
    {
        $filters = [];
        $company = trim((string) (tenant('name') ?? ''));
        if ($company !== '') {
            $filters[] = $company;
        }
        $generated = TenantDisplayFormat::dateTime(now()) ?: now()->format('Y-m-d H:i');
        $filters[] = __('reports.generated_at').': '.$generated;

        return $filters;
    }

    /**
     * @return list<string>
     */
    private static function dateFilters(mixed $from, mixed $to): array
    {
        $out = [];
        if ($from !== null && $from !== '') {
            $out[] = __('reports.date_from').': '.(TenantDisplayFormat::date((string) $from) ?: (string) $from);
        }
        if ($to !== null && $to !== '') {
            $out[] = __('reports.date_to').': '.(TenantDisplayFormat::date((string) $to) ?: (string) $to);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private static function branchFilter(array $report): array
    {
        $id = $report['branch_id'] ?? null;
        if ($id === null || $id === '') {
            return [];
        }

        $name = Branch::query()->whereKey((int) $id)->value('name');
        if ($name === null || $name === '') {
            return [];
        }

        return [__('reports.branch').': '.$name];
    }

    private static function money(mixed $value, string $symbol): string
    {
        $formatted = TenantDisplayFormat::money($value ?? '0');
        if ($formatted === '' || $symbol === '') {
            return $formatted;
        }

        return $formatted.' '.$symbol;
    }

    private static function primarySymbol(): string
    {
        return trim((string) (CompanySetting::current()->primaryCurrency?->symbol ?? ''));
    }
}
