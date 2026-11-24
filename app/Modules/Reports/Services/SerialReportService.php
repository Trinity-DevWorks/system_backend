<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Inventory\Stock\Models\InventorySerial;
use App\Modules\Reports\Support\AnalyticsReport;
use App\Modules\Reports\Support\SqlReportPage;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serial number reports: units in stock (with age), units sold in a period, warranties ending soon.
 * All of them respect the user's visible warehouses.
 */
class SerialReportService
{
    public const IN_STOCK_SORTS = ['serial', 'code', 'name', 'warehouse', 'supplier', 'received_at', 'age_days'];

    public const SOLD_SORTS = ['serial', 'code', 'name', 'customer', 'document', 'sold_at', 'warranty_end'];

    public const WARRANTY_SORTS = ['serial', 'code', 'name', 'customer', 'document', 'sold_at', 'warranty_end', 'days_left'];

    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly WarehouseService $warehouses,
    ) {}

    /**
     * Units on hand now, oldest first.
     *
     * @param  array{search?: ?string, sort?: ?string, dir?: ?string, page?: ?int, per_page?: ?int}  $list
     * @return array<string, mixed>
     */
    public function inStock(?int $warehouseId, ?string $itemId, array $list = []): array
    {
        $today = Carbon::parse($this->fiscal->today())->startOfDay();
        $query = $this->base()
            ->leftJoin('suppliers as sup', 'sup.id', '=', 's.supplier_id')
            ->where('s.status', InventorySerial::STATUS_IN_STOCK)
            ->when($warehouseId !== null, fn ($q) => $q->where('s.warehouse_id', $warehouseId))
            ->when($itemId !== null, fn ($q) => $q->where('s.item_id', $itemId))
            ->selectRaw('s.id AS serial_id, s.serial_number AS serial, i.item_code AS code, i.name AS name, w.name AS warehouse,'
                .' sup.name AS supplier, s.received_at, s.received_ref_type AS source_type, s.received_ref_id AS source_id');

        [$sort, $desc] = SqlReportPage::sort($list, self::IN_STOCK_SORTS, 'received_at', false);
        $result = $this->page($query, $list, [
            'serial' => 'LOWER(r.serial)', 'code' => 'LOWER(r.code)', 'name' => 'LOWER(r.name)', 'warehouse' => 'LOWER(r.warehouse)',
            'supplier' => 'LOWER(r.supplier)', 'received_at' => 'r.received_at', 'age_days' => 'r.received_at',
        ], $sort, $sort === 'age_days' ? ! $desc : $desc, ['supplier', 'received_at', 'age_days'], ['r.serial', 'r.code', 'r.name', 'r.warehouse', 'r.supplier']);

        $numbers = $this->numbers($result['rows']);
        $rows = array_map(fn (array $row): array => [
            'serial_id' => (int) $row['serial_id'],
            'serial' => $row['serial'],
            'code' => $row['code'],
            'name' => $row['name'],
            'warehouse' => $row['warehouse'],
            'supplier' => $row['supplier'],
            'document' => $this->number($row, $numbers),
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'],
            'received_at' => $row['received_at'] ? Carbon::parse((string) $row['received_at'])->toDateString() : null,
            'age_days' => $row['received_at'] ? (int) Carbon::parse((string) $row['received_at'])->startOfDay()->diffInDays($today) : null,
        ], $result['rows']);

        return $this->report(
            [['serial', 'text'], ['code', 'text'], ['name', 'text'], ['warehouse', 'text'], ['supplier', 'text'], ['document', 'text'], ['received_at', 'date'], ['age_days', 'int']],
            $rows,
            null,
            ['as_of' => $today->toDateString(), 'warehouse_id' => $warehouseId, 'item_id' => $itemId],
            $result,
            $list,
            self::IN_STOCK_SORTS,
            $sort,
            $desc,
        );
    }

    /**
     * Units sold in a period (sold_at between from and to), with the customer, the sale and the warranty end.
     *
     * @param  array{search?: ?string, sort?: ?string, dir?: ?string, page?: ?int, per_page?: ?int}  $list
     * @return array<string, mixed>
     */
    public function sold(?string $from, ?string $to, ?int $warehouseId, ?string $itemId, array $list = []): array
    {
        $query = $this->soldQuery($warehouseId, $itemId)
            ->when($from !== null, fn ($q) => $q->where('s.sold_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($to !== null, fn ($q) => $q->where('s.sold_at', '<=', Carbon::parse($to)->endOfDay()));

        [$sort, $desc] = SqlReportPage::sort($list, self::SOLD_SORTS, 'sold_at', true);
        $result = $this->page($query, $list, $this->soldOrder(), $sort, $desc, ['customer', 'warranty_end'], ['r.serial', 'r.code', 'r.name', 'r.customer']);
        $rows = $this->soldRows($result['rows'], null);

        return $this->report(
            [['serial', 'text'], ['code', 'text'], ['name', 'text'], ['customer', 'text'], ['document', 'text'], ['sold_at', 'date'], ['warranty_end', 'date']],
            $rows,
            null,
            ['from' => $from, 'to' => $to, 'warehouse_id' => $warehouseId, 'item_id' => $itemId],
            $result,
            $list,
            self::SOLD_SORTS,
            $sort,
            $desc,
        );
    }

    /**
     * Sold units whose warranty ends within N days (optionally also the ones already ended).
     *
     * @param  array{search?: ?string, sort?: ?string, dir?: ?string, page?: ?int, per_page?: ?int}  $list
     * @return array<string, mixed>
     */
    public function warrantyExpiring(?int $days, bool $includeExpired, ?int $warehouseId, ?string $itemId, array $list = []): array
    {
        $days ??= 30;
        $today = Carbon::parse($this->fiscal->today())->startOfDay();
        $until = $today->copy()->addDays($days)->toDateString();
        $query = $this->soldQuery($warehouseId, $itemId)
            ->whereNotNull('s.warranty_ends_on')
            ->where('s.warranty_ends_on', '<=', $until)
            ->when(! $includeExpired, fn ($q) => $q->where('s.warranty_ends_on', '>=', $today->toDateString()));

        [$sort, $desc] = SqlReportPage::sort($list, self::WARRANTY_SORTS, 'warranty_end', false);
        $result = $this->page($query, $list, [...$this->soldOrder(), 'days_left' => 'r.warranty_ends_on'], $sort, $desc, ['customer'], ['r.serial', 'r.code', 'r.name', 'r.customer']);
        $rows = $this->soldRows($result['rows'], $today);

        return $this->report(
            [['serial', 'text'], ['code', 'text'], ['name', 'text'], ['customer', 'text'], ['document', 'text'], ['sold_at', 'date'], ['warranty_end', 'date'], ['days_left', 'int']],
            $rows,
            null,
            ['as_of' => $today->toDateString(), 'days' => $days, 'include_expired' => $includeExpired, 'warehouse_id' => $warehouseId, 'item_id' => $itemId],
            $result,
            $list,
            self::WARRANTY_SORTS,
            $sort,
            $desc,
        );
    }

    private function base(): Builder
    {
        $query = DB::table('inventory_serials as s')
            ->join('items as i', 'i.id', '=', 's.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id');
        $this->warehouses->applyVisibleWarehouseConstraint($query, 's.warehouse_id');

        return $query;
    }

    private function soldQuery(?int $warehouseId, ?string $itemId): Builder
    {
        return $this->base()
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.status', InventorySerial::STATUS_SOLD)
            ->when($warehouseId !== null, fn ($q) => $q->where('s.warehouse_id', $warehouseId))
            ->when($itemId !== null, fn ($q) => $q->where('s.item_id', $itemId))
            ->selectRaw('s.id AS serial_id, s.serial_number AS serial, i.item_code AS code, i.name AS name, c.name AS customer,'
                .' s.sold_at, s.warranty_ends_on, s.sold_ref_type AS source_type, s.sold_ref_id AS source_id');
    }

    /**
     * @return array<string, string>
     */
    private function soldOrder(): array
    {
        return [
            'serial' => 'LOWER(r.serial)', 'code' => 'LOWER(r.code)', 'name' => 'LOWER(r.name)', 'customer' => 'LOWER(r.customer)',
            'document' => 'r.source_id', 'sold_at' => 'r.sold_at', 'warranty_end' => 'r.warranty_ends_on',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function soldRows(array $rows, ?Carbon $today): array
    {
        $numbers = $this->numbers($rows);

        return array_map(function (array $row) use ($numbers, $today): array {
            $end = $row['warranty_ends_on'] ? Carbon::parse((string) $row['warranty_ends_on'])->startOfDay() : null;
            $out = [
                'serial_id' => (int) $row['serial_id'],
                'serial' => $row['serial'],
                'code' => $row['code'],
                'name' => $row['name'],
                'customer' => $row['customer'],
                'document' => $this->number($row, $numbers),
                'source_type' => $row['source_type'],
                'source_id' => $row['source_id'],
                'sold_at' => $row['sold_at'] ? Carbon::parse((string) $row['sold_at'])->toDateString() : null,
                'warranty_end' => $end?->toDateString(),
            ];
            if ($today !== null) {
                $out['days_left'] = $end !== null ? (int) $today->diffInDays($end, false) : null;
            }

            return $out;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $list
     * @param  array<string, string>  $orderColumns
     * @param  list<string>  $nullable
     * @param  list<string>  $searchExprs
     * @return array{total: int, page: int, rows: list<array<string, mixed>>, sums: array<string, mixed>}
     */
    private function page(Builder $query, array $list, array $orderColumns, string $sort, bool $desc, array $nullable, array $searchExprs): array
    {
        $search = trim((string) ($list['search'] ?? ''));

        return SqlReportPage::run(
            $query,
            $search !== '' ? $search : null,
            $searchExprs,
            SqlReportPage::orderBy($orderColumns, $sort, $desc, $nullable, 'r.serial ASC, r.serial_id ASC'),
            [],
            isset($list['page']) ? (int) $list['page'] : null,
            ! empty($list['per_page']) ? (int) $list['per_page'] : null,
        );
    }

    /**
     * @param  list<array{0: string, 1: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     * @param  array<string, mixed>  $meta
     * @param  array{total: int, page: int, rows: list<mixed>}  $result
     * @param  array<string, mixed>  $list
     * @param  list<string>  $sortable
     * @return array<string, mixed>
     */
    private function report(array $columns, array $rows, ?array $totals, array $meta, array $result, array $list, array $sortable, string $sort, bool $desc): array
    {
        $search = trim((string) ($list['search'] ?? ''));
        $report = AnalyticsReport::make($columns, $rows, $totals, [...$meta, 'search' => $search !== '' ? $search : null]);
        $perPage = ! empty($list['per_page']) ? (int) $list['per_page'] : null;
        $report['pagination'] = $perPage !== null ? SqlReportPage::meta($result, $perPage, $sortable, $sort, $desc) : null;

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function numbers(array $rows): array
    {
        return SourceDocuments::numbers(array_map(fn (array $r): array => [(string) $r['source_type'], $r['source_id']], $rows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $numbers
     */
    private function number(array $row, array $numbers): ?string
    {
        if (empty($row['source_type']) || empty($row['source_id'])) {
            return null;
        }

        return $numbers[$row['source_type'].':'.$row['source_id']] ?? null;
    }
}
