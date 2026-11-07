<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Reports\Support\SqlReportPage;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;

/**
 * Sales analysis grouped in the database (PostgreSQL), with search, sort and pagination.
 *
 * Same rules as SalesProfitService::contributions(), done in SQL so only one page of groups
 * leaves the database:
 *  - events: posted/reversed documents count +1 on their date, a reversal counts -1 on its date;
 *  - line net = (line_total - tax) / document rate, truncated to 8 decimals, rounded to the money scale;
 *  - line cost: share of the document's stock movement cost (per item, or per bundle code), or for
 *    lines invoiced from a delivery note, that note's unit cost; credit notes only for restocked lines;
 *  - divisions truncate at 12 decimals; row values round to 4 (qty 6, margin 1).
 */
class SalesAnalysisQuery
{
    private const WORK = 12;

    public function __construct(private readonly JournalPostingService $journalPosting) {}

    /**
     * @param  array<string, mixed>  $filters  branch scope (branch_id or branch_ids)
     * @return array{rows: list<array<string, mixed>>, sums: array{qty: string, sales: string, returns: string, cost: string}, total: int, page: int}
     */
    public function run(
        string $from,
        string $to,
        string $groupBy,
        array $filters,
        ?string $search,
        string $sort,
        bool $desc,
        ?int $page,
        ?int $perPage,
    ): array {
        [$key, $code, $name] = match ($groupBy) {
            'customer' => ['c.customer_id::text', 'NULL::text', 'c.customer_name'],
            'salesman' => ["COALESCE(c.salesman_id::text, '-')", 'NULL::text', "COALESCE(c.salesman_name, '—')"],
            'branch' => ['c.branch_id::text', 'NULL::text', 'c.branch_name'],
            'category' => ["COALESCE(c.category_id::text, '-')", 'NULL::text', "COALESCE(c.category_name, '—')"],
            default => ['c.item_id::text', 'c.item_code', 'c.item_name'],
        };
        [$cte, $bindings] = $this->contributionsCte($from, $to, $filters);
        $sql = $cte.",
g AS (
    SELECT {$key} AS gkey, MIN({$code}) AS code, MIN({$name}) AS name,
        SUM(c.qty) AS qty, SUM(c.sales) AS sales, SUM(c.returns) AS returns, SUM(c.cost) AS cost
    FROM c
    GROUP BY 1
)
SELECT g.gkey, g.code, g.name, ROUND(g.qty, 6) AS qty, ".$this->amountColumns('g').' FROM g';

        $columns = ['code' => "LOWER(COALESCE(r.code, ''))", 'name' => 'LOWER(r.name)'];
        foreach (['qty', 'sales', 'returns', 'net_sales', 'cost', 'gross_profit', 'margin'] as $k) {
            $columns[$k] = 'r.'.$k;
        }
        // Empty margin sorts lowest; ties by name, then a stable key for paging.
        $orderBy = SqlReportPage::orderBy(['net_sales' => 'r.net_sales', ...$columns], $sort, $desc, ['margin'], 'LOWER(r.name) ASC, r.gkey ASC');

        // Totals add the rounded row values (same as the report's totals row).
        $result = SqlReportPage::run(
            [$sql, $bindings],
            $search,
            ['r.name', 'r.code'],
            $orderBy,
            ['qty' => 'r.qty', 'sales' => 'r.sales', 'returns' => 'r.returns', 'cost' => 'r.cost'],
            $page,
            $perPage
        );
        $result['rows'] = array_map(fn (array $r): array => [
            'code' => $r['code'],
            'name' => (string) $r['name'],
            'qty' => (string) $r['qty'],
            ...$this->amounts($r),
        ], $result['rows']);

        return $result;
    }

    /**
     * Invoice profitability: one row per document and event (a reversal shows as its own negative row).
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, sums: array<string, string>, total: int, page: int}
     */
    public function profitability(string $from, string $to, array $filters, ?string $search, string $sort, bool $desc, ?int $page, ?int $perPage): array
    {
        [$cte, $bindings] = $this->contributionsCte($from, $to, $filters);
        $sql = $cte.',
g AS (
    SELECT c.kind, c.doc_id, c.sign, MIN(c.number) AS number, MIN(c.event_date) AS date, MIN(c.customer_name) AS customer,
        SUM(c.sales) AS sales, SUM(c.returns) AS returns, SUM(c.cost) AS cost
    FROM c
    GROUP BY c.kind, c.doc_id, c.sign
)
SELECT g.kind, g.doc_id, g.sign, g.number, g.date, g.customer, '.$this->amountColumns('g').' FROM g';

        $orderBy = SqlReportPage::orderBy([
            'date' => 'r.date', 'number' => 'LOWER(r.number)', 'customer' => 'LOWER(r.customer)',
            'net_sales' => 'r.net_sales', 'cost' => 'r.cost', 'gross_profit' => 'r.gross_profit', 'margin' => 'r.margin',
        ], $sort, $desc, ['margin'], 'r.date ASC, r.number ASC, r.kind ASC, r.doc_id ASC, r.sign DESC');

        $result = SqlReportPage::run(
            [$sql, $bindings],
            $search,
            ['r.number', 'r.customer'],
            $orderBy,
            ['sales' => 'r.sales', 'returns' => 'r.returns', 'cost' => 'r.cost'],
            $page,
            $perPage
        );
        $result['rows'] = array_map(fn (array $r): array => [
            'number' => (string) $r['number'],
            'date' => (string) $r['date'],
            'customer' => (string) $r['customer'],
            'document' => (string) $r['kind'],
            ...$this->amounts($r),
        ], $result['rows']);

        return $result;
    }

    /** Rounded amount columns over a grouped CTE (sales, returns, net, cost, profit, margin). */
    private function amountColumns(string $g): string
    {
        $w = self::WORK;

        return "ROUND({$g}.sales, 4) AS sales, ROUND({$g}.returns, 4) AS returns,"
            ." ROUND({$g}.sales - {$g}.returns, 4) AS net_sales, ROUND({$g}.cost, 4) AS cost,"
            ." ROUND({$g}.sales - {$g}.returns - {$g}.cost, 4) AS gross_profit,"
            ." CASE WHEN {$g}.sales - {$g}.returns = 0 THEN NULL"
            ." ELSE ROUND(TRUNC(TRUNC(({$g}.sales - {$g}.returns - {$g}.cost) / ({$g}.sales - {$g}.returns), {$w}) * 100, {$w}), 1) END AS margin";
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, ?string>
     */
    private function amounts(array $r): array
    {
        return [
            'sales' => (string) $r['sales'],
            'returns' => (string) $r['returns'],
            'net_sales' => (string) $r['net_sales'],
            'cost' => (string) $r['cost'],
            'gross_profit' => (string) $r['gross_profit'],
            'margin' => $r['margin'] !== null ? (string) $r['margin'] : null,
        ];
    }

    /**
     * WITH ... c: one row per document line and event with its signed qty, sales, returns and cost.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function contributionsCte(string $from, string $to, array $filters): array
    {
        $w = self::WORK;
        $scale = JournalMath::scale();
        $bindings = [];

        [$invEvents, $b] = $this->eventsSql('sales_invoices', 'invoice_date', SalesInvoice::REFERENCE_TYPE, $from, $to, $filters);
        array_push($bindings, ...$b);
        [$cnEvents, $b] = $this->eventsSql('sales_credit_notes', 'credit_date', SalesCreditNote::REFERENCE_TYPE, $from, $to, $filters);
        array_push($bindings, ...$b);

        $invCosts = $this->movementCostsSql('inv_ev');
        $cnCosts = $this->movementCostsSql('cn_ev');
        $dnCosts = $this->movementCostsSql('dn_ids');
        array_push($bindings, SalesInvoice::REFERENCE_TYPE, SalesCreditNote::REFERENCE_TYPE, DeliveryNote::REFERENCE_TYPE);

        // Net in the primary currency, rounded per line like PrimaryMoney::convert().
        $net = fn (string $d, string $l): string => "ROUND(TRUNC(CASE WHEN COALESCE({$d}.exchange_rate, 1) > 0"
            ." THEN ({$l}.line_total - {$l}.tax_amount) / COALESCE({$d}.exchange_rate, 1)"
            ." ELSE ({$l}.line_total - {$l}.tax_amount) END, 8), {$scale})";

        $sql = <<<SQL
WITH
inv_ev AS ({$invEvents}),
cn_ev AS ({$cnEvents}),
dn_ids AS (
    SELECT DISTINCT dnl.delivery_note_id AS doc_id
    FROM sales_invoice_lines l
    JOIN delivery_note_lines dnl ON dnl.id = l.delivery_note_line_id
    WHERE l.sales_invoice_id IN (SELECT doc_id FROM inv_ev)
),
inv_mc AS ({$invCosts}),
cn_mc AS ({$cnCosts}),
dn_mc AS ({$dnCosts}),
dn_units AS (
    SELECT u.delivery_note_id, u.item_id,
        CASE WHEN u.qty > 0 THEN TRUNC(u.cost / u.qty, {$w}) ELSE 0 END AS unit
    FROM (
        SELECT dl.delivery_note_id, dl.item_id, SUM(dl.base_quantity) AS qty,
            COALESCE(MAX(mi.cost), MAX(mb.cost), 0) AS cost
        FROM delivery_note_lines dl
        JOIN items i ON i.id = dl.item_id
        LEFT JOIN dn_mc mi ON mi.doc_id = dl.delivery_note_id AND mi.k = 'i' AND mi.key = dl.item_id::text
        LEFT JOIN dn_mc mb ON mb.doc_id = dl.delivery_note_id AND mb.k = 'b' AND mb.key = COALESCE(i.item_code, '')
        WHERE dl.delivery_note_id IN (SELECT doc_id FROM dn_ids)
        GROUP BY dl.delivery_note_id, dl.item_id
    ) u
),
inv_lines AS (
    SELECT l.sales_invoice_id AS doc_id, d.invoice_number AS number, l.item_id, l.base_quantity AS q, l.delivery_note_line_id, dnl.delivery_note_id,
        {$net('d', 'l')} AS net,
        COALESCE(mi.cost, mb.cost, 0) AS doc_cost,
        SUM(CASE WHEN l.delivery_note_line_id IS NULL THEN l.base_quantity END) OVER (PARTITION BY l.sales_invoice_id, l.item_id) AS share,
        d.customer_id, cu.name AS customer_name, d.salesman_id, s.full_name AS salesman_name, d.branch_id, b.name AS branch_name,
        i.item_code, i.name AS item_name, i.category_id, c.name AS category_name
    FROM sales_invoice_lines l
    JOIN sales_invoices d ON d.id = l.sales_invoice_id
    JOIN items i ON i.id = l.item_id
    LEFT JOIN categories c ON c.id = i.category_id
    JOIN customers cu ON cu.id = d.customer_id
    LEFT JOIN salesmen s ON s.id = d.salesman_id
    JOIN branches b ON b.id = d.branch_id
    LEFT JOIN delivery_note_lines dnl ON dnl.id = l.delivery_note_line_id
    LEFT JOIN inv_mc mi ON mi.doc_id = l.sales_invoice_id AND mi.k = 'i' AND mi.key = l.item_id::text
    LEFT JOIN inv_mc mb ON mb.doc_id = l.sales_invoice_id AND mb.k = 'b' AND mb.key = COALESCE(i.item_code, '')
    WHERE l.sales_invoice_id IN (SELECT doc_id FROM inv_ev)
),
cn_lines AS (
    SELECT l.sales_credit_note_id AS doc_id, d.credit_note_number AS number, l.item_id, l.base_quantity AS q, l.restock,
        {$net('d', 'l')} AS net,
        COALESCE(mi.cost, mb.cost, 0) AS doc_cost,
        SUM(CASE WHEN l.restock THEN l.base_quantity END) OVER (PARTITION BY l.sales_credit_note_id, l.item_id) AS share,
        d.customer_id, cu.name AS customer_name, inv.salesman_id, s.full_name AS salesman_name, d.branch_id, b.name AS branch_name,
        i.item_code, i.name AS item_name, i.category_id, c.name AS category_name
    FROM sales_credit_note_lines l
    JOIN sales_credit_notes d ON d.id = l.sales_credit_note_id
    LEFT JOIN sales_invoices inv ON inv.id = d.sales_invoice_id
    JOIN items i ON i.id = l.item_id
    LEFT JOIN categories c ON c.id = i.category_id
    JOIN customers cu ON cu.id = d.customer_id
    LEFT JOIN salesmen s ON s.id = inv.salesman_id
    JOIN branches b ON b.id = d.branch_id
    LEFT JOIN cn_mc mi ON mi.doc_id = l.sales_credit_note_id AND mi.k = 'i' AND mi.key = l.item_id::text
    LEFT JOIN cn_mc mb ON mb.doc_id = l.sales_credit_note_id AND mb.k = 'b' AND mb.key = COALESCE(i.item_code, '')
    WHERE l.sales_credit_note_id IN (SELECT doc_id FROM cn_ev)
),
c AS (
    SELECT 'invoice' AS kind, x.doc_id::text AS doc_id, x.number, e.event_date, e.sign,
        x.item_id, x.item_code, x.item_name, x.category_id, x.category_name, x.customer_id, x.customer_name,
        x.salesman_id, x.salesman_name, x.branch_id, x.branch_name,
        e.sign * x.q AS qty, e.sign * x.net AS sales, 0::numeric AS returns,
        e.sign * CASE
            WHEN x.delivery_note_line_id IS NOT NULL THEN TRUNC(COALESCE(u.unit, 0) * x.q, {$w})
            WHEN x.share > 0 THEN TRUNC(TRUNC(x.doc_cost * x.q, {$w}) / x.share, {$w})
            ELSE 0
        END AS cost
    FROM inv_lines x
    JOIN inv_ev e ON e.doc_id = x.doc_id
    LEFT JOIN dn_units u ON u.delivery_note_id = x.delivery_note_id AND u.item_id = x.item_id
    UNION ALL
    SELECT 'credit_note', x.doc_id::text, x.number, e.event_date, e.sign,
        x.item_id, x.item_code, x.item_name, x.category_id, x.category_name, x.customer_id, x.customer_name,
        x.salesman_id, x.salesman_name, x.branch_id, x.branch_name,
        -e.sign * x.q, 0::numeric, e.sign * x.net,
        -e.sign * CASE
            WHEN x.restock AND x.share > 0 THEN TRUNC(TRUNC(x.doc_cost * x.q, {$w}) / x.share, {$w})
            ELSE 0
        END
    FROM cn_lines x
    JOIN cn_ev e ON e.doc_id = x.doc_id
)
SQL;

        return [$sql, $bindings];
    }

    /**
     * Document events: +1 on the document date (posted or reversed), -1 on the reversal date.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function eventsSql(string $table, string $dateColumn, string $sourceType, string $from, string $to, array $filters): array
    {
        [$branch, $branchBindings] = $this->branchSql($filters);

        // Opening invoices / credits are old balances, not sales of the period.
        $positive = "SELECT d.id AS doc_id, 1 AS sign, d.{$dateColumn}::date AS event_date FROM {$table} d WHERE {$branch}"
            ." AND NOT d.is_opening AND d.status IN ('posted', 'reversed') AND d.{$dateColumn} BETWEEN ? AND ?";
        $bindings = [...$branchBindings, $from, $to];

        if ($this->journalPosting->enabled()) {
            $negative = "SELECT d.id, -1, je.entry_date::date FROM {$table} d JOIN journal_entries je ON je.source_id = d.id::text"
                ." AND je.source_type = ? AND je.source_event = ? AND je.status <> 'draft'"
                ." WHERE {$branch} AND je.entry_date BETWEEN ? AND ?";
            array_push($bindings, $sourceType, JournalEntry::EVENT_REVERSE, ...$branchBindings);
            array_push($bindings, $from, $to);
        } else {
            $negative = "SELECT d.id, -1, d.updated_at::date FROM {$table} d WHERE {$branch}"
                ." AND d.status = 'reversed' AND d.updated_at::date >= ? AND d.updated_at::date <= ?";
            array_push($bindings, ...$branchBindings);
            array_push($bindings, $from, $to);
        }

        return [$positive.' UNION ALL '.$negative, $bindings];
    }

    /**
     * Original (not "Reverse") stock movement cost per document, as a positive amount:
     * k = 'i' per item id, k = 'b' per bundle code (components tagged "... bundle CODE").
     * The reference type is bound by the caller.
     */
    private function movementCostsSql(string $docsCte): string
    {
        $ws = "' ' || chr(9) || chr(10) || chr(13) || chr(11)";

        return 'SELECT s.doc_id,'
            ." CASE WHEN s.type = 'bundle_sale' OR (s.type = 'sales_return' AND s.bcode IS NOT NULL) THEN 'b' ELSE 'i' END AS k,"
            ." CASE WHEN s.type = 'bundle_sale' OR (s.type = 'sales_return' AND s.bcode IS NOT NULL) THEN s.bcode ELSE s.item_id::text END AS key,"
            .' SUM(ABS(s.value_delta)) AS cost'
            .' FROM ('
            ."  SELECT m.reference_id AS doc_id, m.item_id, m.type, m.value_delta, btrim(substring(m.notes from '^.* bundle (.*)$'), {$ws}) AS bcode"
            .'  FROM stock_movements m'
            ."  WHERE m.reference_type = ? AND m.reference_id IN (SELECT doc_id FROM {$docsCte})"
            ."  AND (m.notes IS NULL OR m.notes NOT LIKE 'Reverse%')"
            .' ) s'
            ." WHERE NOT (s.type = 'bundle_sale' AND s.bcode IS NULL)"
            .' GROUP BY 1, 2, 3';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function branchSql(array $filters): array
    {
        if (isset($filters['branch_id'])) {
            return ['d.branch_id = ?', [(int) $filters['branch_id']]];
        }
        if (array_key_exists('branch_ids', $filters)) {
            $ids = array_map('intval', (array) $filters['branch_ids']);
            if ($ids === []) {
                return ['1 = 0', []];
            }

            return ['d.branch_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')', $ids];
        }

        return ['1 = 1', []];
    }
}
