<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\PriceChange\Enums\PriceField;
use App\Modules\Inventory\PriceChange\Models\ItemPriceChange;
use App\Modules\Inventory\PriceChange\Support\PriceChangeMath;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Reports\Support\AnalyticsReport;
use App\Support\Decimal;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Support\Facades\DB;

final class PriceReportService
{
    /**
     * Drawer payload: every change for one item, plus a selling-price series per unit.
     *
     * @return array{rows: list<array<string, mixed>>, series: list<array<string, mixed>>}
     */
    public function timeline(string $itemId): array
    {
        $rows = $this->historyRows($itemId);
        $series = [];
        foreach ($rows as $row) {
            if ($row['field'] !== PriceField::Selling->value || $row['new_value'] === null || $row['item_uom_id'] === null) {
                continue;
            }
            $key = (string) $row['item_uom_id'];
            if (! isset($series[$key])) {
                $series[$key] = [
                    'item_uom_id' => (int) $row['item_uom_id'],
                    'uom' => $row['uom'],
                    'field' => PriceField::Selling->value,
                    'points' => [],
                ];
            }
            $series[$key]['points'][] = [
                'date' => substr((string) $row['effective_at'], 0, 10),
                'value' => $row['new_value'],
            ];
        }
        foreach ($series as &$one) {
            $one['points'] = array_reverse($one['points']);
        }
        unset($one);

        return [
            'rows' => $rows,
            'series' => array_values($series),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(?string $itemId): array
    {
        if ($itemId === null || $itemId === '') {
            abort(422, 'Choose an item.', ['X-Error-Code' => 'REPORT_ITEM_REQUIRED']);
        }
        $item = Item::query()->find($itemId, ['id', 'item_code', 'name']);
        if ($item === null) {
            abort(422, 'Choose an item.', ['X-Error-Code' => 'REPORT_ITEM_REQUIRED']);
        }
        $rows = array_map(fn (array $row): array => [
            'date' => substr((string) $row['effective_at'], 0, 10),
            'field' => $row['field'],
            'unit' => $row['uom'],
            'price_list' => $row['price_list'],
            'min_quantity' => $row['min_quantity'],
            'old_value' => $row['old_value'],
            'new_value' => $row['new_value'],
            'change_percent' => $row['change_percent'],
            'user' => $row['user'],
            'source' => $row['source'],
            'reason' => $row['reason'],
        ], $this->historyRows($itemId));

        return AnalyticsReport::make(
            [
                ['date', 'date'],
                ['field', 'text'],
                ['unit', 'text'],
                ['price_list', 'text'],
                ['min_quantity', 'qty'],
                ['old_value', 'money'],
                ['new_value', 'money'],
                ['change_percent', 'percent'],
                ['user', 'text'],
                ['source', 'text'],
                ['reason', 'text'],
            ],
            $rows,
            null,
            ['item_id' => $itemId, 'item' => trim(($item->item_code ?? '').' '.($item->name ?? ''))],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function changesByPeriod(array $filters): array
    {
        $query = DB::table('item_price_changes as c')
            ->join('items as i', 'i.id', '=', 'c.item_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.changed_by')
            ->whereNull('i.deleted_at')
            ->select([
                'c.effective_at',
                'i.item_code',
                'i.name as item_name',
                'c.field',
                'c.old_value',
                'c.new_value',
                'c.change_percent',
                'u.name as user_name',
                'c.source',
            ])
            ->orderByDesc('c.effective_at')
            ->orderByDesc('c.id');
        if (! empty($filters['from'])) {
            $query->where('c.effective_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('c.effective_at', '<=', $filters['to'].' 23:59:59');
        }
        if (! empty($filters['category_id'])) {
            $query->where('i.category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('c.changed_by', $filters['user_id']);
        }
        if (! empty($filters['source'])) {
            $query->where('c.source', $filters['source']);
        }
        if (! empty($filters['field'])) {
            $query->where('c.field', $filters['field']);
        }
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('i.item_code', 'ilike', $like)
                    ->orWhere('i.name', 'ilike', $like)
                    ->orWhere('u.name', 'ilike', $like);
            });
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = [
                'date' => substr((string) $row->effective_at, 0, 10),
                'item' => trim($row->item_code.' '.$row->item_name),
                'field' => (string) $row->field,
                'old_value' => $row->old_value !== null ? (string) $row->old_value : null,
                'new_value' => $row->new_value !== null ? (string) $row->new_value : null,
                'change_percent' => $row->change_percent !== null ? (string) $row->change_percent : null,
                'user' => $row->user_name,
                'source' => (string) $row->source,
                'changes' => 1,
            ];
        }

        return AnalyticsReport::make(
            [
                ['date', 'date'],
                ['item', 'text'],
                ['field', 'text'],
                ['old_value', 'money'],
                ['new_value', 'money'],
                ['change_percent', 'percent'],
                ['user', 'text'],
                ['source', 'text'],
                ['changes', 'int'],
            ],
            $rows,
            ['changes' => count($rows)],
            [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'user_id' => $filters['user_id'] ?? null,
                'source' => $filters['source'] ?? null,
                'field' => $filters['field'] ?? null,
            ],
        );
    }

    /**
     * Selling price of a unit against the current average cost (base cost × conversion).
     *
     * @return array<string, mixed>
     */
    public function belowCost(): array
    {
        $averages = DB::table('stock_balances')
            ->where('quantity', '>', 0)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(unit_cost * quantity) as extended, SUM(quantity) as qty')
            ->get()
            ->keyBy('item_id');

        $uoms = DB::table('item_uoms as iu')
            ->join('items as i', 'i.id', '=', 'iu.item_id')
            ->join('unit_of_measurements as u', 'u.id', '=', 'iu.uom_id')
            ->whereNull('i.deleted_at')
            ->whereNotNull('iu.selling_price')
            ->orderBy('i.item_code')
            ->orderByDesc('iu.is_base')
            ->get(['i.item_code', 'i.name', 'i.id as item_id', 'u.code as uom', 'iu.selling_price', 'iu.conversion_factor']);

        $rows = [];
        foreach ($uoms as $uom) {
            $avg = $averages->get($uom->item_id);
            if ($avg === null || ! Decimal::isPositive($avg->qty)) {
                continue;
            }
            $cost = Decimal::mul(Decimal::div($avg->extended, $avg->qty, 6), (string) $uom->conversion_factor, 6);
            $selling = Decimal::round((string) $uom->selling_price, 6);
            if (Decimal::cmp($selling, $cost, 6) >= 0) {
                continue;
            }
            $rows[] = [
                'item' => trim($uom->item_code.' '.$uom->name),
                'unit' => (string) $uom->uom,
                'selling_price' => $selling,
                'cost' => $cost,
                'margin' => PriceChangeMath::marginPercent($selling, $cost),
                'gap' => Decimal::round(Decimal::sub($selling, $cost, 6), 6),
            ];
        }

        $gap = '0';
        foreach ($rows as $row) {
            $gap = Decimal::add($gap, $row['gap'], 6);
        }

        return AnalyticsReport::make(
            [
                ['item', 'text'],
                ['unit', 'text'],
                ['selling_price', 'money'],
                ['cost', 'money'],
                ['margin', 'percent'],
                ['gap', 'money'],
            ],
            $rows,
            ['gap' => $gap],
            [],
        );
    }

    /**
     * Posted purchase invoice lines, unit price converted to the primary currency.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function purchaseHistory(array $filters): array
    {
        $query = DB::table('purchase_invoice_lines as l')
            ->join('purchase_invoices as p', 'p.id', '=', 'l.purchase_invoice_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.status', PurchaseInvoiceStatus::Posted->value)
            ->whereNull('i.deleted_at')
            ->orderByDesc('p.invoice_date')
            ->orderByDesc('p.invoice_number')
            ->select([
                'p.invoice_date',
                'p.invoice_number',
                'p.exchange_rate',
                'i.item_code',
                'i.name as item_name',
                's.name as supplier_name',
                'l.unit_price',
            ]);
        if (! empty($filters['from'])) {
            $query->whereDate('p.invoice_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('p.invoice_date', '<=', $filters['to']);
        }
        if (! empty($filters['item_id'])) {
            $query->where('l.item_id', $filters['item_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('p.supplier_id', $filters['supplier_id']);
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = [
                'date' => substr((string) $row->invoice_date, 0, 10),
                'item' => trim($row->item_code.' '.$row->item_name),
                'supplier' => (string) $row->supplier_name,
                'document' => (string) ($row->invoice_number ?? ''),
                'unit_price' => ExchangeRateSnapshot::toPrimary((string) $row->unit_price, (string) $row->exchange_rate, 6),
                'changes' => 1,
            ];
        }

        return AnalyticsReport::make(
            [
                ['date', 'date'],
                ['item', 'text'],
                ['supplier', 'text'],
                ['document', 'text'],
                ['unit_price', 'money'],
                ['changes', 'int'],
            ],
            $rows,
            ['changes' => count($rows)],
            [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'item_id' => $filters['item_id'] ?? null,
                'supplier_id' => $filters['supplier_id'] ?? null,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRows(string $itemId): array
    {
        return ItemPriceChange::query()
            ->with(['user:id,name', 'itemUom.uom:id,code', 'priceList:id,code'])
            ->where('item_id', $itemId)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ItemPriceChange $row): array => [
                'id' => (int) $row->id,
                'item_uom_id' => $row->item_uom_id !== null ? (int) $row->item_uom_id : null,
                'uom' => $row->itemUom?->uom?->code,
                'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
                'price_list' => $row->priceList?->code,
                'field' => $row->field,
                'min_quantity' => $row->min_quantity !== null ? (string) $row->min_quantity : null,
                'old_value' => $row->old_value !== null ? (string) $row->old_value : null,
                'new_value' => $row->new_value !== null ? (string) $row->new_value : null,
                'change_percent' => $row->change_percent !== null ? (string) $row->change_percent : null,
                'source' => $row->source instanceof \BackedEnum ? $row->source->value : (string) $row->source,
                'source_id' => $row->source_id,
                'reason' => $row->reason,
                'effective_at' => $row->effective_at->toIso8601String(),
                'changed_by' => $row->changed_by,
                'user' => $row->user?->name,
            ])
            ->all();
    }
}
