<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\AnalyticsReport;
use App\Modules\Reports\Support\SqlReportPage;
use App\Support\Decimal;
use Illuminate\Support\Carbon;

/**
 * Waste in a period: customer returns written off as waste (posted credit notes) and stock taken out as waste
 * (posted stock adjustments with a waste reason: spoiled, expired, damaged). Quantity in the item's base unit,
 * cost at what the goods cost (the amount that went to the Waste account).
 */
class WasteReportService
{
    public const SORTS = ['day', 'branch', 'code', 'name', 'source', 'reason', 'document', 'qty', 'cost'];

    /**
     * @param  array{search?: ?string, sort?: ?string, dir?: ?string, page?: ?int, per_page?: ?int}  $list
     * @return array<string, mixed>
     */
    public function waste(?string $from, ?string $to, ?int $branchId, ?string $itemId, ?string $source, array $list = []): array
    {
        $from = $from !== null ? Carbon::parse($from)->toDateString() : null;
        $to = $to !== null ? Carbon::parse($to)->toDateString() : null;
        $source = in_array($source, ['return', 'spoilage'], true) ? $source : null;

        $parts = [];
        $bindings = [];
        if ($source !== 'spoilage') {
            [$where, $b] = $this->filters('n.credit_date', 'n.branch_id', 'l.item_id', $from, $to, $branchId, $itemId);
            $parts[] = "SELECT 'return' AS source, n.credit_date AS day, b.name AS branch, i.item_code AS code, i.name AS name,"
                .' n.reason AS reason, l.base_quantity AS qty, l.waste_value AS cost, n.credit_note_number AS document,'
                ." 'sales_credit_note' AS source_type, n.id::text AS source_id"
                .' FROM sales_credit_note_lines l'
                .' JOIN sales_credit_notes n ON n.id = l.sales_credit_note_id'
                .' JOIN items i ON i.id = l.item_id'
                .' LEFT JOIN branches b ON b.id = n.branch_id'
                ." WHERE n.status = 'posted' AND l.return_action = 'waste'".$where;
            $bindings = [...$bindings, ...$b];
        }
        if ($source !== 'return') {
            [$where, $b] = $this->filters('a.adjustment_date', 'a.branch_id', 'm.item_id', $from, $to, $branchId, $itemId);
            $parts[] = "SELECT 'spoilage' AS source, a.adjustment_date AS day, b.name AS branch, i.item_code AS code, i.name AS name,"
                .' r.name AS reason, ABS(m.quantity_delta) AS qty, ABS(m.value_delta) AS cost, a.adj_number AS document,'
                ." 'stock_adjustment' AS source_type, a.id::text AS source_id"
                .' FROM stock_movements m'
                ." JOIN stock_adjustments a ON m.reference_type = 'stock_adjustment' AND a.id = m.reference_id"
                .' JOIN stock_adjustment_reasons r ON r.id = a.stock_adjustment_reason_id'
                .' JOIN items i ON i.id = m.item_id'
                .' LEFT JOIN branches b ON b.id = a.branch_id'
                ." WHERE r.is_waste AND a.status = 'posted' AND m.quantity_delta < 0"
                ." AND (m.notes IS NULL OR m.notes NOT LIKE 'Reverse%')".$where;
            $bindings = [...$bindings, ...$b];
        }
        $sql = implode(' UNION ALL ', $parts);

        [$sort, $desc] = SqlReportPage::sort($list, self::SORTS, 'day', true);
        $search = trim((string) ($list['search'] ?? ''));
        $result = SqlReportPage::run(
            [$sql, $bindings],
            $search !== '' ? $search : null,
            ['r.code', 'r.name', 'r.document', 'r.branch', 'r.reason'],
            SqlReportPage::orderBy([
                'day' => 'r.day', 'branch' => 'LOWER(r.branch)', 'code' => 'LOWER(r.code)', 'name' => 'LOWER(r.name)',
                'source' => 'r.source', 'reason' => 'LOWER(r.reason)', 'document' => 'r.document', 'qty' => 'r.qty', 'cost' => 'r.cost',
            ], $sort, $desc, ['branch', 'reason'], 'r.day DESC, r.document ASC'),
            ['cost' => 'r.cost'],
            isset($list['page']) ? (int) $list['page'] : null,
            ! empty($list['per_page']) ? (int) $list['per_page'] : null,
        );

        $rows = array_map(fn (array $row): array => [
            'day' => $row['day'] !== null ? Carbon::parse((string) $row['day'])->toDateString() : null,
            'branch' => $row['branch'],
            'code' => $row['code'],
            'name' => $row['name'],
            'source' => __('reports.waste_source_'.$row['source']),
            'reason' => $row['reason'],
            'document' => $row['document'],
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'],
            'qty' => Decimal::round((string) $row['qty'], 4),
            'cost' => Decimal::round((string) $row['cost'], 4),
        ], $result['rows']);

        $report = AnalyticsReport::make(
            [['day', 'date'], ['branch', 'text'], ['code', 'text'], ['name', 'text'], ['source', 'text'], ['reason', 'text'], ['document', 'text'], ['qty', 'qty'], ['cost', 'money']],
            $rows,
            ['cost' => Decimal::round($result['sums']['cost'] ?? '0', 4)],
            ['from' => $from, 'to' => $to, 'branch_id' => $branchId, 'item_id' => $itemId, 'source' => $source, 'search' => $search !== '' ? $search : null],
        );
        $perPage = ! empty($list['per_page']) ? (int) $list['per_page'] : null;
        $report['pagination'] = $perPage !== null ? SqlReportPage::meta($result, $perPage, self::SORTS, $sort, $desc) : null;

        return $report;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function filters(string $dateCol, string $branchCol, string $itemCol, ?string $from, ?string $to, ?int $branchId, ?string $itemId): array
    {
        $where = '';
        $bindings = [];
        if ($from !== null) {
            $where .= " AND {$dateCol} >= ?";
            $bindings[] = $from;
        }
        if ($to !== null) {
            $where .= " AND {$dateCol} <= ?";
            $bindings[] = $to;
        }
        if ($branchId !== null) {
            $where .= " AND {$branchCol} = ?";
            $bindings[] = $branchId;
        }
        if ($itemId !== null) {
            $where .= " AND {$itemCol} = ?";
            $bindings[] = $itemId;
        }

        return [$where, $bindings];
    }
}
