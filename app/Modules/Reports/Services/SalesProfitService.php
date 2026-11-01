<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Reports\Support\AnalyticsReport;
use App\Modules\Reports\Support\PrimaryMoney;
use App\Modules\Reports\Support\SqlReportPage;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales analysis (by item / customer / salesman / branch / category) and invoice profitability.
 *
 * Amounts are net of VAT in the primary currency at each document's rate. Posted and reversed
 * invoices and credit notes count on their document date; a reversal counts again, negative,
 * on the reversal date (same rule as the period reports).
 *
 * Cost of sales per invoice line comes from stock movements:
 *  - direct lines: the invoice's own movements (sale, or bundle components tagged "bundle CODE"),
 *  - lines invoiced from a delivery note: that delivery note's movements, at its unit cost,
 *  - credit notes: the restocked return movements (non-restocked returns put no cost back).
 */
class SalesProfitService
{
    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly ReportBranchScope $branches,
        private readonly JournalPostingService $journalPosting,
        private readonly SalesAnalysisQuery $analysisQuery,
    ) {}

    /** Columns the sales analysis table can be sorted by. */
    public const ANALYSIS_SORTS = ['code', 'name', 'qty', 'sales', 'returns', 'net_sales', 'cost', 'gross_profit', 'margin'];

    /**
     * Sales grouped by item / customer / salesman / branch / category.
     * $list: search (name or code), sort + dir, and page + per_page (no per_page = all rows, used by exports).
     * Totals always cover every row that matches the search, not only the current page.
     *
     * @param  array{search?: ?string, sort?: ?string, dir?: ?string, page?: ?int, per_page?: ?int}  $list
     * @return array<string, mixed>
     */
    public function analysis(?string $from, ?string $to, ?string $groupBy, ?int $branchId, array $list = []): array
    {
        [$from, $to, $scope] = $this->period($from, $to, $branchId);
        $groupBy = in_array($groupBy, ['item', 'customer', 'salesman', 'branch', 'category'], true) ? $groupBy : 'item';

        $search = trim((string) ($list['search'] ?? ''));
        [$sort, $desc] = SqlReportPage::sort($list, self::ANALYSIS_SORTS, 'net_sales', true);
        $perPage = ! empty($list['per_page']) ? (int) $list['per_page'] : null;

        // Grouped, searched, sorted and paged in the database; only one page of groups comes back.
        $result = $this->analysisQuery->run(
            $from,
            $to,
            $groupBy,
            $scope['filters'],
            $search !== '' ? $search : null,
            $sort,
            $desc,
            isset($list['page']) ? (int) $list['page'] : null,
            $perPage,
        );
        $rows = $result['rows'];

        $totals = $this->profitRow([], $result['sums']['sales'], $result['sums']['returns'], $result['sums']['cost']);
        if ($groupBy === 'item') {
            $totals['qty'] = Decimal::round($result['sums']['qty'], 6);
        }
        $meta = ['from' => $from, 'to' => $to, 'group_by' => $groupBy, 'branch_id' => $scope['branch_id'], 'search' => $search !== '' ? $search : null];

        $pagination = $perPage !== null
            ? SqlReportPage::meta($result, $perPage, $groupBy === 'item' ? self::ANALYSIS_SORTS : array_values(array_diff(self::ANALYSIS_SORTS, ['code', 'qty'])), $sort, $desc)
            : null;

        $columns = $groupBy === 'item'
            ? [['code', 'text'], ['name', 'text'], ['qty', 'qty']]
            : [['name', 'text']];

        $report = AnalyticsReport::make(
            [...$columns, ['sales', 'money'], ['returns', 'money'], ['net_sales', 'money'], ['cost', 'money'], ['gross_profit', 'money'], ['margin', 'percent']],
            $rows,
            $totals,
            $meta,
        );
        $report['pagination'] = $pagination;

        return $report;
    }

    /** Columns the invoice profitability table can be sorted by. */
    public const PROFITABILITY_SORTS = ['date', 'number', 'customer', 'net_sales', 'cost', 'gross_profit', 'margin'];

    /**
     * Gross profit per document (credit notes as negative rows), in SQL with search / sort / pagination.
     *
     * @param  array{search?: ?string, sort?: ?string, dir?: ?string, page?: ?int, per_page?: ?int}  $list
     * @return array<string, mixed>
     */
    public function profitability(?string $from, ?string $to, ?int $branchId, array $list = []): array
    {
        [$from, $to, $scope] = $this->period($from, $to, $branchId);
        $search = trim((string) ($list['search'] ?? ''));
        [$sort, $desc] = SqlReportPage::sort($list, self::PROFITABILITY_SORTS, 'date', false);
        $perPage = ! empty($list['per_page']) ? (int) $list['per_page'] : null;

        $result = $this->analysisQuery->profitability(
            $from,
            $to,
            $scope['filters'],
            $search !== '' ? $search : null,
            $sort,
            $desc,
            isset($list['page']) ? (int) $list['page'] : null,
            $perPage,
        );

        $report = AnalyticsReport::make(
            [['number', 'text'], ['date', 'date'], ['customer', 'text'], ['net_sales', 'money'], ['cost', 'money'], ['gross_profit', 'money'], ['margin', 'percent']],
            $result['rows'],
            $this->profitRow([], $result['sums']['sales'], $result['sums']['returns'], $result['sums']['cost']),
            ['from' => $from, 'to' => $to, 'branch_id' => $scope['branch_id'], 'search' => $search !== '' ? $search : null],
        );
        $report['pagination'] = $perPage !== null ? SqlReportPage::meta($result, $perPage, self::PROFITABILITY_SORTS, $sort, $desc) : null;

        return $report;
    }

    /**
     * One row per document line and event (post +1 on the document date, reversal -1 on its date).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function contributions(string $from, string $to, array $filters): array
    {
        $invoiceEvents = $this->events('sales_invoices', 'invoice_date', SalesInvoice::REFERENCE_TYPE, $from, $to, $filters);
        $noteEvents = $this->events('sales_credit_notes', 'credit_date', SalesCreditNote::REFERENCE_TYPE, $from, $to, $filters);

        $out = [];
        if ($invoiceEvents !== []) {
            array_push($out, ...$this->invoiceContributions($invoiceEvents));
        }
        if ($noteEvents !== []) {
            array_push($out, ...$this->creditNoteContributions($noteEvents));
        }

        return $out;
    }

    /**
     * @param  array<string, list<array{date: string, sign: int}>>  $events
     * @return list<array<string, mixed>>
     */
    private function invoiceContributions(array $events): array
    {
        $ids = array_keys($events);
        $lines = DB::table('sales_invoice_lines as l')
            ->join('sales_invoices as d', 'd.id', '=', 'l.sales_invoice_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('categories as c', 'c.id', '=', 'i.category_id')
            ->join('customers as cu', 'cu.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->join('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('delivery_note_lines as dnl', 'dnl.id', '=', 'l.delivery_note_line_id')
            ->whereIn('d.id', $ids)
            ->get([
                'l.id', 'l.sales_invoice_id as document_id', 'l.item_id', 'l.base_quantity', 'l.line_total', 'l.tax_amount',
                'l.delivery_note_line_id', 'dnl.delivery_note_id',
                'd.invoice_number as number', 'd.exchange_rate', 'd.customer_id', 'd.salesman_id', 'd.branch_id',
                'i.item_code', 'i.name as item_name', 'i.category_id', 'c.name as category_name',
                'cu.name as customer_name', 's.full_name as salesman_name', 'b.name as branch_name',
            ]);

        $invoiceCosts = $this->movementCosts(SalesInvoice::REFERENCE_TYPE, $ids);
        $dnIds = $lines->pluck('delivery_note_id')->filter()->unique()->values()->all();
        $dnCosts = $this->movementCosts(DeliveryNote::REFERENCE_TYPE, $dnIds);
        $dnUnits = $this->deliveryUnitCosts($dnIds, $dnCosts);

        // Quantity per document and item (or bundle) to share a document's cost among its lines.
        $shares = [];
        foreach ($lines as $line) {
            if ($line->delivery_note_line_id === null) {
                $k = $line->document_id.'|'.$line->item_id;
                $shares[$k] = Decimal::add($shares[$k] ?? '0', $line->base_quantity);
            }
        }

        $out = [];
        foreach ($lines as $line) {
            if ($line->delivery_note_line_id !== null) {
                $unit = $dnUnits[$line->delivery_note_id.'|'.$line->item_id] ?? '0';
                $cost = Decimal::mul($unit, $line->base_quantity);
            } else {
                $docCost = $invoiceCosts[$line->document_id]['item'][$line->item_id]
                    ?? $invoiceCosts[$line->document_id]['bundle'][(string) $line->item_code]
                    ?? '0';
                $total = $shares[$line->document_id.'|'.$line->item_id] ?? '0';
                $cost = Decimal::isPositive($total) ? Decimal::div(Decimal::mul($docCost, $line->base_quantity), $total) : '0';
            }
            $net = PrimaryMoney::convert(Decimal::sub($line->line_total, $line->tax_amount), $line->exchange_rate);
            foreach ($events[$line->document_id] as $event) {
                $out[] = $this->contribution($line, 'invoice', $event, $line->base_quantity, $net, $cost);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<array{date: string, sign: int}>>  $events
     * @return list<array<string, mixed>>
     */
    private function creditNoteContributions(array $events): array
    {
        $ids = array_keys($events);
        $lines = DB::table('sales_credit_note_lines as l')
            ->join('sales_credit_notes as d', 'd.id', '=', 'l.sales_credit_note_id')
            ->leftJoin('sales_invoices as inv', 'inv.id', '=', 'd.sales_invoice_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('categories as c', 'c.id', '=', 'i.category_id')
            ->join('customers as cu', 'cu.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'inv.salesman_id')
            ->join('branches as b', 'b.id', '=', 'd.branch_id')
            ->whereIn('d.id', $ids)
            ->get([
                'l.id', 'l.sales_credit_note_id as document_id', 'l.item_id', 'l.base_quantity', 'l.line_total', 'l.tax_amount', 'l.restock',
                'd.credit_note_number as number', 'd.exchange_rate', 'd.customer_id', 'inv.salesman_id', 'd.branch_id',
                'i.item_code', 'i.name as item_name', 'i.category_id', 'c.name as category_name',
                'cu.name as customer_name', 's.full_name as salesman_name', 'b.name as branch_name',
            ]);

        $returnCosts = $this->movementCosts(SalesCreditNote::REFERENCE_TYPE, $ids);
        $shares = [];
        foreach ($lines as $line) {
            if ($line->restock) {
                $k = $line->document_id.'|'.$line->item_id;
                $shares[$k] = Decimal::add($shares[$k] ?? '0', $line->base_quantity);
            }
        }

        $out = [];
        foreach ($lines as $line) {
            $cost = '0';
            if ($line->restock) {
                // Return movements carry a positive value (cost put back into stock).
                $docCost = $returnCosts[$line->document_id]['item'][$line->item_id]
                    ?? $returnCosts[$line->document_id]['bundle'][(string) $line->item_code]
                    ?? '0';
                $total = $shares[$line->document_id.'|'.$line->item_id] ?? '0';
                $cost = Decimal::isPositive($total) ? Decimal::div(Decimal::mul($docCost, $line->base_quantity), $total) : '0';
            }
            $net = PrimaryMoney::convert(Decimal::sub($line->line_total, $line->tax_amount), $line->exchange_rate);
            foreach ($events[$line->document_id] as $event) {
                // A return reduces quantity, sales and cost.
                $out[] = $this->contribution($line, 'credit_note', $event, Decimal::neg($line->base_quantity), Decimal::neg($net), Decimal::neg($cost));
            }
        }

        return $out;
    }

    /**
     * @param  array{date: string, sign: int}  $event
     * @return array<string, mixed>
     */
    private function contribution(object $line, string $kind, array $event, string $qty, string $net, string $cost): array
    {
        $sign = $event['sign'];

        return [
            'kind' => $kind,
            'document_id' => (string) $line->document_id,
            'number' => (string) $line->number,
            'event_date' => $event['date'],
            'sign' => $sign,
            'customer_id' => (string) $line->customer_id,
            'customer_name' => (string) $line->customer_name,
            'salesman_id' => $line->salesman_id !== null ? (string) $line->salesman_id : null,
            'salesman_name' => $line->salesman_name,
            'branch_id' => (int) $line->branch_id,
            'branch_name' => (string) $line->branch_name,
            'item_id' => (string) $line->item_id,
            'item_code' => $line->item_code,
            'item_name' => (string) $line->item_name,
            'category_id' => $line->category_id,
            'category_name' => $line->category_name,
            'qty' => $sign > 0 ? Decimal::of($qty) : Decimal::neg($qty),
            'net' => $sign > 0 ? Decimal::of($net) : Decimal::neg($net),
            'cost' => $sign > 0 ? Decimal::of($cost) : Decimal::neg($cost),
        ];
    }

    /**
     * Cost per document from its original (not "Reverse") stock movements, as a positive amount for
     * issues and returns alike: ['item' => [item_id => cost], 'bundle' => [bundle code => cost]].
     *
     * @param  list<string>  $ids
     * @return array<string, array{item: array<string, string>, bundle: array<string, string>, qty: array<string, string>}>
     */
    private function movementCosts(string $referenceType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('stock_movements')
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', $ids)
            ->where(fn (Builder $q) => $q->whereNull('notes')->orWhere('notes', 'not like', 'Reverse%'))
            ->get(['reference_id', 'item_id', 'type', 'notes', 'quantity_delta', 'value_delta']);

        $out = [];
        foreach ($rows as $row) {
            $doc = (string) $row->reference_id;
            $out[$doc] ??= ['item' => [], 'bundle' => [], 'qty' => []];
            $value = Decimal::abs($row->value_delta);
            $bundleCode = $this->bundleCode((string) ($row->notes ?? ''));
            if (in_array($row->type, ['bundle_sale'], true) || ($row->type === 'sales_return' && $bundleCode !== null)) {
                if ($bundleCode !== null) {
                    $out[$doc]['bundle'][$bundleCode] = Decimal::add($out[$doc]['bundle'][$bundleCode] ?? '0', $value);
                }

                continue;
            }
            $item = (string) $row->item_id;
            $out[$doc]['item'][$item] = Decimal::add($out[$doc]['item'][$item] ?? '0', $value);
            $out[$doc]['qty'][$item] = Decimal::add($out[$doc]['qty'][$item] ?? '0', Decimal::abs($row->quantity_delta));
        }

        return $out;
    }

    /**
     * Unit cost per delivery note and delivered item (bundles: per bundle unit).
     *
     * @param  list<string>  $noteIds
     * @param  array<string, array{item: array<string, string>, bundle: array<string, string>, qty: array<string, string>}>  $costs
     * @return array<string, string>
     */
    private function deliveryUnitCosts(array $noteIds, array $costs): array
    {
        if ($noteIds === []) {
            return [];
        }
        $lines = DB::table('delivery_note_lines as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->whereIn('l.delivery_note_id', $noteIds)
            ->get(['l.delivery_note_id', 'l.item_id', 'l.base_quantity', 'i.item_code']);

        $qty = [];
        foreach ($lines as $line) {
            $k = $line->delivery_note_id.'|'.$line->item_id;
            $qty[$k] = Decimal::add($qty[$k] ?? '0', $line->base_quantity);
        }
        $out = [];
        foreach ($lines as $line) {
            $k = $line->delivery_note_id.'|'.$line->item_id;
            $cost = $costs[$line->delivery_note_id]['item'][$line->item_id]
                ?? $costs[$line->delivery_note_id]['bundle'][(string) $line->item_code]
                ?? '0';
            $out[$k] = Decimal::isPositive($qty[$k]) ? Decimal::div($cost, $qty[$k]) : '0';
        }

        return $out;
    }

    private function bundleCode(string $notes): ?string
    {
        $pos = strrpos($notes, ' bundle ');
        if ($pos === false) {
            return null;
        }

        return trim(substr($notes, $pos + 8));
    }

    /**
     * Document ids with their events: posted/reversed on the document date (+1), reversal date (-1).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, list<array{date: string, sign: int}>>
     */
    private function events(string $table, string $dateColumn, string $sourceType, string $from, string $to, array $filters): array
    {
        $events = [];
        $positive = $this->base($table, $filters)
            ->whereIn('d.status', ['posted', 'reversed'])
            ->whereBetween('d.'.$dateColumn, [$from, $to])
            ->get(['d.id', 'd.'.$dateColumn.' as event_date']);
        foreach ($positive as $row) {
            $events[(string) $row->id][] = ['date' => Carbon::parse((string) $row->event_date)->toDateString(), 'sign' => 1];
        }

        $negative = $this->base($table, $filters);
        if ($this->journalPosting->enabled()) {
            $rows = $negative->join('journal_entries as je', function ($join) use ($sourceType): void {
                $join->whereRaw('je.source_id = d.id::text')
                    ->where('je.source_type', '=', $sourceType)
                    ->where('je.source_event', '=', JournalEntry::EVENT_REVERSE)
                    ->where('je.status', '!=', 'draft');
            })->whereBetween('je.entry_date', [$from, $to])->get(['d.id', 'je.entry_date as event_date']);
        } else {
            $rows = $negative->where('d.status', 'reversed')
                ->whereDate('d.updated_at', '>=', $from)
                ->whereDate('d.updated_at', '<=', $to)
                ->get(['d.id', 'd.updated_at as event_date']);
        }
        foreach ($rows as $row) {
            $events[(string) $row->id][] = ['date' => Carbon::parse((string) $row->event_date)->toDateString(), 'sign' => -1];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function base(string $table, array $filters): Builder
    {
        $query = DB::table($table.' as d');
        if (isset($filters['branch_id'])) {
            $query->where('d.branch_id', $filters['branch_id']);
        } elseif (array_key_exists('branch_ids', $filters)) {
            $filters['branch_ids'] === [] ? $query->whereRaw('1 = 0') : $query->whereIn('d.branch_id', $filters['branch_ids']);
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string, 2: array{branch_id: ?int, filters: array<string, mixed>}}
     */
    private function period(?string $from, ?string $to, ?int $branchId): array
    {
        $defaults = $this->fiscal->currentMonthToDate();

        return [$from ?? $defaults['from'], $to ?? $defaults['to'], $this->branches->apply($branchId)];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function profitRow(array $base, string $sales, string $returns, string $cost): array
    {
        $net = Decimal::sub($sales, $returns);
        $profit = Decimal::sub($net, $cost);

        return [
            ...$base,
            'sales' => Decimal::round($sales, 4),
            'returns' => Decimal::round($returns, 4),
            'net_sales' => Decimal::round($net, 4),
            'cost' => Decimal::round($cost, 4),
            'gross_profit' => Decimal::round($profit, 4),
            'margin' => Decimal::isZero($net) ? null : Decimal::round(Decimal::mul(Decimal::div($profit, $net), '100'), 1),
        ];
    }
}
