<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Export\TabularDocument;
use Illuminate\Support\Facades\Lang;

/**
 * Shared shape of the analytics reports, so one exporter (here) and one frontend page render them all:
 *   columns: [{key, type: text|date|money|qty|int|percent}]   labels come from lang "reports.col_{key}"
 *   rows:    list of key => value (row_kind "opening"/"closing"/"section" marks summary rows)
 *   totals:  key => value for the totals row (or null)
 *   meta:    filters used (from, to, branch_id, warehouse_id, item_id, group_by, days, ...)
 */
final class AnalyticsReport
{
    /**
     * @param  list<array{0: string, 1: string}>  $columns  [key, type]
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function make(array $columns, array $rows, ?array $totals, array $meta): array
    {
        return [
            'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'type' => $c[1]], $columns),
            'rows' => $rows,
            'totals' => $totals,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function document(array $report, string $titleKey, string $filename): TabularDocument
    {
        $symbol = trim((string) (CompanySetting::current()->primaryCurrency?->symbol ?? ''));
        /** @var list<array{key: string, type: string}> $columns */
        $columns = $report['columns'];

        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = array_map(fn (array $c): string => self::cell(self::code($c['key'], $row[$c['key']] ?? null), $c['type'], $symbol), $columns);
        }
        if (is_array($report['totals'] ?? null)) {
            $totals = array_map(fn (array $c): string => array_key_exists($c['key'], $report['totals'])
                ? self::cell($report['totals'][$c['key']], $c['type'], $symbol)
                : '', $columns);
            $totals[0] = __('reports.totals');
            $rows[] = $totals;
        }

        return new TabularDocument(
            title: __('reports.'.$titleKey),
            filename: $filename,
            headings: array_map(fn (array $c): string => __('reports.col_'.$c['key']), $columns),
            rows: $rows,
            filters: self::filters($report['meta'] ?? []),
        );
    }

    /** Category / movement type / opening-closing codes become readable labels. */
    private static function code(string $key, mixed $value): mixed
    {
        if (! in_array($key, ['category', 'type', 'description', 'field', 'source'], true) || ! is_string($value) || $value === '') {
            return $value;
        }
        $langKey = 'reports.val_'.$value;

        return Lang::has($langKey) ? __($langKey) : $value;
    }

    private static function cell(mixed $value, string $type, string $symbol): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => trim(TenantDisplayFormat::money($value).' '.$symbol),
            'qty' => TenantDisplayFormat::quantity($value),
            'date' => TenantDisplayFormat::date((string) $value) ?: (string) $value,
            'percent' => $value.'%',
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private static function filters(array $meta): array
    {
        $out = ReportDocuments::headerFilters();
        foreach (['from' => 'date_from', 'to' => 'date_to', 'as_of' => 'as_of'] as $key => $label) {
            if (! empty($meta[$key])) {
                $out[] = __('reports.'.$label).': '.(TenantDisplayFormat::date((string) $meta[$key]) ?: (string) $meta[$key]);
            }
        }
        if (! empty($meta['group_by'])) {
            $out[] = __('reports.group_by').': '.__('reports.group_'.$meta['group_by']);
        }
        if (! empty($meta['branch_id'])) {
            $out[] = __('reports.branch').': '.(string) Branch::query()->whereKey((int) $meta['branch_id'])->value('name');
        }
        if (! empty($meta['warehouse_id'])) {
            $out[] = __('reports.warehouse').': '.(string) Warehouse::query()->whereKey((int) $meta['warehouse_id'])->value('name');
        }
        if (! empty($meta['item_id'])) {
            $item = Item::query()->find($meta['item_id'], ['item_code', 'name']);
            $out[] = __('reports.item').': '.trim(($item?->item_code ?? '').' '.($item?->name ?? ''));
        }
        if (! empty($meta['search'])) {
            $out[] = __('reports.search').': '.(string) $meta['search'];
        }
        if (! empty($meta['uom'])) {
            $out[] = __('reports.unit').': '.(string) $meta['uom'];
        }
        if (isset($meta['days']) && $meta['days'] !== null) {
            $out[] = __('reports.days').': '.(string) $meta['days'];
        }

        return $out;
    }
}
