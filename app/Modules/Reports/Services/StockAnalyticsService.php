<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Reports\Support\AnalyticsReport;
use App\Modules\Reports\Support\SqlReportPage;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock reports: lot expiry, dead / slow-moving stock, item movement history.
 * All of them respect the user's visible warehouses.
 */
class StockAnalyticsService
{
    /** Movement types that take stock out for use (not corrections). */
    private const OUTBOUND = ['sale', 'bundle_sale', 'transfer_out', 'production_out', 'purchase_return'];

    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly WarehouseService $warehouses,
    ) {}

    /**
     * Lots on hand that expire within N days (and, optionally, already expired ones).
     *
     * @return array<string, mixed>
     */
    /** Columns the lot expiry table can be sorted by. */
    public const LOT_EXPIRY_SORTS = ['expiry_date', 'days_left', 'code', 'name', 'warehouse', 'lot', 'quantity', 'value'];

    /** Columns the dead stock table can be sorted by. */
    public const DEAD_STOCK_SORTS = ['days_idle', 'last_movement', 'code', 'name', 'warehouse', 'quantity', 'value'];

    /**
     * @param  array{search?: ?string, sort?: ?string, dir?: ?string, page?: ?int, per_page?: ?int}  $list
     */
    public function lotExpiry(?int $days, bool $includeExpired, ?int $warehouseId, array $list = []): array
    {
        $days ??= 30;
        $today = Carbon::parse($this->fiscal->today())->startOfDay();
        $until = $today->copy()->addDays($days)->toDateString();

        $query = DB::table('stock_balances as sb')
            ->join('inventory_lots as lot', 'lot.id', '=', 'sb.lot_id')
            ->join('items as i', 'i.id', '=', 'sb.item_id')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->where('sb.quantity', '>', 0)
            ->whereNotNull('lot.expiry_date')
            ->where('lot.expiry_date', '<=', $until)
            ->when(! $includeExpired, fn ($q) => $q->where('lot.expiry_date', '>=', $today->toDateString()))
            ->when($warehouseId !== null, fn ($q) => $q->where('sb.warehouse_id', $warehouseId))
            ->selectRaw('sb.id AS row_id, i.item_code AS code, i.name AS name, w.name AS warehouse, lot.lot_number AS lot, lot.expiry_date,'
                .' sb.quantity, ROUND(sb.quantity * COALESCE(sb.unit_cost, 0), 4) AS value');
        $this->warehouses->applyVisibleWarehouseConstraint($query, 'sb.warehouse_id');

        $search = trim((string) ($list['search'] ?? ''));
        [$sort, $desc] = SqlReportPage::sort($list, self::LOT_EXPIRY_SORTS, 'expiry_date', false);
        $perPage = ! empty($list['per_page']) ? (int) $list['per_page'] : null;
        $orderBy = SqlReportPage::orderBy([
            'expiry_date' => 'r.expiry_date', 'days_left' => 'r.expiry_date',
            'code' => 'LOWER(r.code)', 'name' => 'LOWER(r.name)', 'warehouse' => 'LOWER(r.warehouse)', 'lot' => 'LOWER(r.lot)',
            'quantity' => 'r.quantity', 'value' => 'r.value',
        ], $sort, $desc, [], 'r.expiry_date ASC, r.code ASC, r.row_id ASC');
        // Totals: quantity summed exactly, value as the sum of the rounded row values.
        $result = SqlReportPage::run(
            $query,
            $search !== '' ? $search : null,
            ['r.code', 'r.name', 'r.warehouse', 'r.lot'],
            $orderBy,
            ['quantity' => 'r.quantity', 'value' => 'r.value'],
            isset($list['page']) ? (int) $list['page'] : null,
            $perPage
        );

        $rows = array_map(function (array $row) use ($today): array {
            $left = (int) $today->diffInDays(Carbon::parse((string) $row['expiry_date'])->startOfDay(), false);

            return [
                'code' => $row['code'],
                'name' => $row['name'],
                'warehouse' => $row['warehouse'],
                'lot' => $row['lot'],
                'expiry_date' => Carbon::parse((string) $row['expiry_date'])->toDateString(),
                'days_left' => $left,
                'status' => $left < 0 ? 'expired' : 'expiring',
                'quantity' => Decimal::round($row['quantity'], 6),
                'value' => Decimal::round($row['value'], 4),
            ];
        }, $result['rows']);

        $report = AnalyticsReport::make(
            [['code', 'text'], ['name', 'text'], ['warehouse', 'text'], ['lot', 'text'], ['expiry_date', 'date'], ['days_left', 'int'], ['quantity', 'qty'], ['value', 'money']],
            $rows,
            ['quantity' => Decimal::round($result['sums']['quantity'], 6), 'value' => Decimal::round($result['sums']['value'], 4)],
            ['as_of' => $today->toDateString(), 'days' => $days, 'warehouse_id' => $warehouseId, 'include_expired' => $includeExpired, 'search' => $search !== '' ? $search : null],
        );
        $report['pagination'] = $perPage !== null ? SqlReportPage::meta($result, $perPage, self::LOT_EXPIRY_SORTS, $sort, $desc) : null;

        return $report;
    }

    /**
     * Items with stock on hand and no outbound movement (sale, transfer out, production use,
     * purchase return) for at least N days.
     *
     * @return array<string, mixed>
     */
    public function deadStock(?int $days, ?int $warehouseId, array $list = []): array
    {
        $days ??= 90;
        $today = Carbon::parse($this->fiscal->today())->endOfDay();
        $cutoff = $today->copy()->subDays($days);

        $lastOut = DB::table('stock_movements')
            ->whereIn('type', self::OUTBOUND)
            ->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, MAX(created_at) as last_out');
        $firstIn = DB::table('stock_movements')
            ->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, MIN(created_at) as first_in');

        $query = DB::table('stock_balances as sb')
            ->join('items as i', 'i.id', '=', 'sb.item_id')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->leftJoinSub($lastOut, 'lo', fn ($j) => $j->on('lo.item_id', '=', 'sb.item_id')->on('lo.warehouse_id', '=', 'sb.warehouse_id'))
            ->leftJoinSub($firstIn, 'fi', fn ($j) => $j->on('fi.item_id', '=', 'sb.item_id')->on('fi.warehouse_id', '=', 'sb.warehouse_id'))
            ->when($warehouseId !== null, fn ($q) => $q->where('sb.warehouse_id', $warehouseId))
            ->groupBy('sb.item_id', 'sb.warehouse_id', 'i.item_code', 'i.name', 'w.name', 'lo.last_out', 'fi.first_in')
            ->havingRaw('SUM(sb.quantity) > 0')
            ->havingRaw('COALESCE(lo.last_out, fi.first_in) <= ?', [$cutoff])
            ->selectRaw('sb.item_id, sb.warehouse_id, i.item_code AS code, i.name AS name, w.name AS warehouse, lo.last_out, fi.first_in,'
                .' COALESCE(lo.last_out, fi.first_in) AS since, SUM(sb.quantity) AS quantity, COALESCE(SUM(sb.inventory_value), 0) AS value');
        $this->warehouses->applyVisibleWarehouseConstraint($query, 'sb.warehouse_id');

        $search = trim((string) ($list['search'] ?? ''));
        [$sort, $desc] = SqlReportPage::sort($list, self::DEAD_STOCK_SORTS, 'days_idle', true);
        $perPage = ! empty($list['per_page']) ? (int) $list['per_page'] : null;
        // Most idle = oldest last movement, so days_idle sorts by "since" the other way round.
        $orderBy = SqlReportPage::orderBy([
            'days_idle' => '(-EXTRACT(EPOCH FROM r.since))', 'last_movement' => 'r.last_out',
            'code' => 'LOWER(r.code)', 'name' => 'LOWER(r.name)', 'warehouse' => 'LOWER(r.warehouse)',
            'quantity' => 'r.quantity', 'value' => 'r.value',
        ], $sort, $desc, ['last_movement'], 'r.since ASC, r.code ASC, r.warehouse_id ASC');
        $result = SqlReportPage::run(
            $query,
            $search !== '' ? $search : null,
            ['r.code', 'r.name', 'r.warehouse'],
            $orderBy,
            ['quantity' => 'r.quantity', 'value' => 'r.value'],
            isset($list['page']) ? (int) $list['page'] : null,
            $perPage
        );

        $rows = array_map(function (array $row) use ($today): array {
            $since = Carbon::parse((string) $row['since']);

            return [
                'code' => $row['code'],
                'name' => $row['name'],
                'warehouse' => $row['warehouse'],
                'last_movement' => $row['last_out'] !== null ? Carbon::parse((string) $row['last_out'])->toDateString() : null,
                'days_idle' => (int) $since->startOfDay()->diffInDays($today->copy()->startOfDay()),
                'quantity' => Decimal::round($row['quantity'], 6),
                'value' => Decimal::round($row['value'], 4),
            ];
        }, $result['rows']);

        $report = AnalyticsReport::make(
            [['code', 'text'], ['name', 'text'], ['warehouse', 'text'], ['last_movement', 'date'], ['days_idle', 'int'], ['quantity', 'qty'], ['value', 'money']],
            $rows,
            ['quantity' => Decimal::round($result['sums']['quantity'], 6), 'value' => Decimal::round($result['sums']['value'], 4)],
            ['as_of' => $today->toDateString(), 'days' => $days, 'warehouse_id' => $warehouseId, 'search' => $search !== '' ? $search : null],
        );
        $report['pagination'] = $perPage !== null ? SqlReportPage::meta($result, $perPage, self::DEAD_STOCK_SORTS, $sort, $desc) : null;

        return $report;
    }

    /**
     * Item movements for a period: opening, each movement with its document number, running balance, closing.
     *
     * @return array<string, mixed>
     */
    public function itemMovement(string $itemId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $defaults = $this->fiscal->currentMonthToDate();
        $from ??= $defaults['from'];
        $to ??= $defaults['to'];
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $base = fn () => DB::table('stock_movements as m')
            ->where('m.item_id', $itemId)
            ->when($warehouseId !== null, fn ($q) => $q->where('m.warehouse_id', $warehouseId));

        $openingQuery = $base()->where('m.created_at', '<', $start);
        $this->warehouses->applyVisibleWarehouseConstraint($openingQuery, 'm.warehouse_id');
        $openingRow = $openingQuery->selectRaw('COALESCE(SUM(m.quantity_delta), 0) as qty, COALESCE(SUM(m.value_delta), 0) as value')->first();
        $opening = Decimal::of($openingRow->qty ?? '0');

        $query = $base()
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('inventory_lots as lot', 'lot.id', '=', 'm.lot_id')
            ->whereBetween('m.created_at', [$start, $end])
            ->orderBy('m.created_at')
            ->orderBy('m.id');
        $this->warehouses->applyVisibleWarehouseConstraint($query, 'm.warehouse_id');
        $movements = $query->get(['m.created_at', 'm.type', 'm.reference_type', 'm.reference_id', 'm.notes', 'm.quantity_delta', 'm.unit_cost', 'm.value_delta', 'w.name as warehouse_name', 'lot.lot_number']);

        $numbers = SourceDocuments::numbers($movements->map(fn ($m): array => [(string) $m->reference_type, $m->reference_id]));

        // Stock movements are stored in the item's base unit; the report says which one.
        $item = DB::table('items as i')
            ->leftJoin('unit_of_measurements as u', 'u.id', '=', 'i.base_uom_id')
            ->where('i.id', $itemId)
            ->first(['u.name as uom', 'i.track_lots']);
        $uom = $item?->uom;
        // Lot column only for lot-tracked items (or when a movement still carries a lot).
        $showLot = (bool) ($item?->track_lots ?? false) || $movements->contains(fn ($m) => $m->lot_number !== null);

        $rows = [[
            'row_kind' => 'opening',
            'date' => $start->toDateString(),
            'type' => 'opening_balance',
            'balance' => Decimal::round($opening, 6),
        ]];
        $balance = $opening;
        $in = '0';
        $out = '0';
        foreach ($movements as $m) {
            $balance = Decimal::add($balance, $m->quantity_delta);
            Decimal::isPositive($m->quantity_delta) ? $in = Decimal::add($in, $m->quantity_delta) : $out = Decimal::add($out, Decimal::abs($m->quantity_delta));
            $rows[] = [
                'date' => Carbon::parse((string) $m->created_at)->toDateString(),
                'type' => (string) $m->type,
                'document' => $numbers[$m->reference_type.':'.$m->reference_id] ?? null,
                'warehouse' => $m->warehouse_name,
                'lot' => $m->lot_number,
                'qty_in' => Decimal::isPositive($m->quantity_delta) ? Decimal::round($m->quantity_delta, 6) : null,
                'qty_out' => Decimal::isNegative($m->quantity_delta) ? Decimal::round(Decimal::abs($m->quantity_delta), 6) : null,
                'unit_cost' => $m->unit_cost !== null ? (string) $m->unit_cost : null,
                'balance' => Decimal::round($balance, 6),
                'reversal' => str_starts_with((string) ($m->notes ?? ''), 'Reverse'),
            ];
        }
        $rows[] = [
            'row_kind' => 'closing',
            'date' => $end->toDateString(),
            'type' => 'closing_balance',
            'balance' => Decimal::round($balance, 6),
        ];

        return AnalyticsReport::make(
            [['date', 'date'], ['type', 'text'], ['document', 'text'], ['warehouse', 'text'], ...($showLot ? [['lot', 'text']] : []), ['qty_in', 'qty'], ['qty_out', 'qty'], ['unit_cost', 'money'], ['balance', 'qty']],
            $rows,
            ['qty_in' => Decimal::round($in, 6), 'qty_out' => Decimal::round($out, 6), 'balance' => Decimal::round($balance, 6)],
            ['from' => $from, 'to' => $to, 'item_id' => $itemId, 'warehouse_id' => $warehouseId, 'uom' => $uom !== null ? (string) $uom : null],
        );
    }
}
