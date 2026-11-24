<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\PriceChange\Enums\PriceField;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleExport;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleExportTemplate;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleSheetExport;
use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Sales\PriceList\Models\PriceList;
use App\Modules\Sales\PriceList\Models\PriceListItem;
use App\Support\Decimal;
use App\Support\Export\DelimitedFileWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the item file a scale's PC software imports (PLU, name, price …), keeps the export history,
 * and tells which items changed since the last export (the "scale prices out of date" warning).
 */
class ScaleExportService
{
    /** Values a column can show. */
    public const FIELDS = [
        'plu', 'plu_padded', 'item_code', 'name', 'second_name', 'barcode', 'unit_code',
        'price_type', 'price', 'shelf_life_days', 'category', 'vat_rate', 'constant',
    ];

    /** @var array<int, array<int, string>> price list id => item_uom_id => price (quantity 1) */
    private array $listPrices = [];

    /**
     * Items the file contains: active items sold at the scale (optionally only some categories, or only
     * those changed since this template's last export).
     *
     * @return Collection<int, Item>
     */
    public function items(ScaleExportTemplate $template, bool $onlyChanged): Collection
    {
        return $this->partition($template, $this->candidates($template, $onlyChanged))[0];
    }

    /**
     * Before the missing-price rule: every scale item the template selects.
     *
     * @return Collection<int, Item>
     */
    private function candidates(ScaleExportTemplate $template, bool $onlyChanged): Collection
    {
        $query = $this->baseQuery($template);
        if ($onlyChanged) {
            $since = $this->lastExport($template)?->created_at;
            if ($since !== null) {
                $changedIds = $this->changedItemIds($template, (string) $since);
                $query->whereIn('items.id', $changedIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $changedIds);
            }
        }

        return $query
            ->with(['category:id,name', 'vatGroup:id,percentage', 'unitGroup:id,dimension_type', 'barcodes', 'itemUoms.uom:id,code,name'])
            ->orderByRaw("ltrim(plu_code, '0')::bigint")
            ->get();
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, item_count: int, currency: array{code: string, symbol: string}|null, missing_price: array{count: int, names: list<string>}}
     */
    public function preview(ScaleExportTemplate $template, bool $onlyChanged, int $limit = 20): array
    {
        [$items, $missing] = $this->partition($template, $this->candidates($template, $onlyChanged));

        return [
            'headers' => array_map(fn (array $c): string => (string) ($c['header'] ?? $c['field'] ?? ''), $this->columns($template)),
            'rows' => $this->rows($template, $items->take($limit)),
            'item_count' => $items->count(),
            'currency' => $this->currency($template),
            'missing_price' => $this->missingSummary($missing),
        ];
    }

    /**
     * The currency the file's prices are in: the template's price list currency, else the primary one.
     * The file itself carries plain numbers (scale software adds its own symbol).
     *
     * @return array{code: string, symbol: string}|null
     */
    public function currency(ScaleExportTemplate $template): ?array
    {
        $currency = $template->price_source === 'price_list' && $template->price_list_id !== null
            ? PriceList::query()->with('currency:id,code,symbol')->find($template->price_list_id)?->currency
            : null;
        $currency ??= CompanySetting::current()->primaryCurrency;

        return $currency !== null ? ['code' => (string) $currency->code, 'symbol' => trim((string) $currency->symbol)] : null;
    }

    /**
     * The file, plus a history row.
     */
    public function download(ScaleExportTemplate $template, bool $onlyChanged, ?string $userId): Response
    {
        [$items, $missing] = $this->partition($template, $this->candidates($template, $onlyChanged));
        if ($items->isEmpty() && $missing->isNotEmpty()) {
            abort(422, 'None of these items has a price on the template\'s price list.', [
                'X-Error-Code' => 'SCALE_EXPORT_NO_LIST_PRICES',
                'X-Error-Params' => (string) json_encode(['count' => $missing->count()]),
            ]);
        }
        if ($items->isEmpty()) {
            abort(422, $onlyChanged ? 'No scale item changed since the last export.' : 'No active items are sold at the scale.', [
                'X-Error-Code' => $onlyChanged ? 'SCALE_EXPORT_NOTHING_CHANGED' : 'SCALE_EXPORT_NO_ITEMS',
            ]);
        }

        $rows = $this->rows($template, $items);
        $extension = $template->file_extension;
        $fileName = Str::slug($template->name ?: 'scale-items').'-'.now()->format('Ymd-His').'.'.$extension;

        DB::transaction(function () use ($template, $onlyChanged, $items, $fileName, $userId): void {
            ScaleExport::query()->create([
                'scale_export_template_id' => $template->id,
                'branch_id' => $template->branch_id,
                'only_changed' => $onlyChanged,
                'item_count' => $items->count(),
                'item_ids' => $items->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                'file_name' => $fileName,
                'exported_by' => $userId,
                'created_at' => now(),
            ]);
        });

        if ($extension === 'xlsx') {
            $columns = $this->columns($template);
            $sheet = $template->has_header
                ? [array_map(fn (array $c): string => (string) ($c['header'] ?? $c['field'] ?? ''), $columns), ...$rows]
                : $rows;
            $fields = array_map(fn (array $c): string => (string) $c['field'], $columns);

            return Excel::download(
                new ScaleSheetExport($sheet, $fields, (bool) $template->has_header, (string) $template->decimal_separator),
                pathinfo($fileName, PATHINFO_FILENAME).'.xlsx',
            );
        }

        $columns = $this->columns($template);
        $all = $template->has_header
            ? [array_map(fn (array $c): string => (string) ($c['header'] ?? $c['field'] ?? ''), $columns), ...$rows]
            : $rows;
        $content = DelimitedFileWriter::write($all, [
            'delimiter' => $template->delimiter,
            'quote' => $template->quote,
            'encoding' => $template->encoding,
            'line_ending' => $template->line_ending,
            'widths' => array_map(fn (array $c): ?int => isset($c['width']) && $c['width'] !== '' ? (int) $c['width'] : null, $columns),
            'pad_chars' => array_map(fn (array $c): ?string => isset($c['pad_char']) ? (string) $c['pad_char'] : null, $columns),
            'pad_sides' => array_map(fn (array $c): ?string => isset($c['pad_side']) ? (string) $c['pad_side'] : null, $columns),
        ]);
        $charset = $template->encoding === 'windows1256' ? 'windows-1256' : 'utf-8';

        return response($content, 200, [
            'Content-Type' => ($extension === 'csv' ? 'text/csv' : 'text/plain').'; charset='.$charset,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /**
     * Per template: when it was last exported and how many items changed since.
     *
     * @return list<array<string, mixed>>
     */
    public function outdated(): array
    {
        $out = [];
        foreach (ScaleExportTemplate::query()->where('is_active', true)->orderBy('name')->get() as $template) {
            $last = $this->lastExport($template);
            $total = $this->baseQuery($template)->count();
            $changedIds = $last === null ? null : $this->changedItemIds($template, (string) $last->created_at);
            $missingIds = [];
            if ($this->foreignList($template)) {
                // Items without a price on a foreign-currency list are not in the file: don't count them.
                $missingIds = $this->partition($template, $this->candidates($template, false))[1]
                    ->map(fn (Item $item): string => (string) $item->id)->all();
                $total -= count($missingIds);
                $changedIds = $changedIds === null ? null : array_values(array_diff($changedIds, $missingIds));
            }
            $out[] = [
                'template_id' => (int) $template->id,
                'template_name' => $template->name,
                'item_count' => $total,
                'changed_count' => $changedIds === null ? $total : count($changedIds),
                'missing_price_count' => count($missingIds),
                'never_exported' => $last === null,
                'last_export' => $last ? [
                    'id' => (int) $last->id,
                    'created_at' => $last->created_at->toIso8601String(),
                    'item_count' => $last->item_count,
                    'only_changed' => $last->only_changed,
                    'user' => $last->user ? ['id' => (string) $last->user->id, 'name' => $last->user->name] : null,
                ] : null,
            ];
        }

        return $out;
    }

    public function lastExport(ScaleExportTemplate $template): ?ScaleExport
    {
        return ScaleExport::query()
            ->with('user:id,name')
            ->where('scale_export_template_id', $template->id)
            ->latest('id')
            ->first();
    }

    /**
     * Scale items whose price, name or PLU changed after $since: price history rows for the
     * template's price (item selling price or its price list), and item edits.
     *
     * @return list<string>
     */
    public function changedItemIds(ScaleExportTemplate $template, string $since): array
    {
        $now = now();
        $priceChanged = DB::table('item_price_changes')
            ->where('effective_at', '>', $since)
            ->where('effective_at', '<=', $now)
            ->where(function ($q) use ($template): void {
                $q->where(fn ($q) => $q->whereNull('price_list_id')->where('field', PriceField::Selling->value));
                if ($template->price_source === 'price_list' && $template->price_list_id !== null) {
                    $q->orWhere(fn ($q) => $q->where('price_list_id', $template->price_list_id)->where('field', PriceField::PriceList->value));
                }
            })
            ->distinct()
            ->pluck('item_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $edited = $this->baseQuery($template)->where('items.updated_at', '>', $since)->pluck('items.id')
            ->map(fn ($id): string => (string) $id)->all();

        $ids = array_values(array_unique([...$priceChanged, ...$edited]));
        if ($ids === []) {
            return [];
        }

        return $this->baseQuery($template)->whereIn('items.id', $ids)->pluck('items.id')
            ->map(fn ($id): string => (string) $id)->values()->all();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<list<string>>
     */
    public function rows(ScaleExportTemplate $template, Collection $items): array
    {
        $columns = $this->columns($template);
        $rows = [];
        foreach ($items as $item) {
            $uom = $this->saleUnit($item);
            $row = [];
            foreach ($columns as $column) {
                $row[] = $this->cell($template, $column, $item, $uom);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function columns(ScaleExportTemplate $template): array
    {
        return array_values(array_filter(
            (array) $template->columns,
            fn ($c): bool => is_array($c) && in_array($c['field'] ?? null, self::FIELDS, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function cell(ScaleExportTemplate $template, array $column, Item $item, ?ItemUom $uom): string
    {
        $field = (string) $column['field'];
        $isWeight = $item->unitGroup?->dimension_type === DimensionType::Weight;

        return match ($field) {
            'plu' => (string) $item->plu_code,
            'plu_padded' => str_pad(ltrim((string) $item->plu_code, '0') ?: '0', max(1, (int) ($column['width'] ?? 5)), '0', STR_PAD_LEFT),
            'item_code' => (string) ($item->item_code ?? ''),
            'name' => (string) $item->name,
            'second_name' => (string) ($item->pos_name ?: $item->kitchen_name ?: ''),
            'barcode' => $this->primaryBarcode($item, $uom),
            'unit_code' => (string) ($uom?->uom?->code ?? ''),
            'price_type' => (string) (($column['map'][$isWeight ? 'weight' : 'piece'] ?? null) ?? ($isWeight ? 'weight' : 'piece')),
            'price' => $this->formatNumber($this->price($template, $item, $uom), $template),
            'shelf_life_days' => $item->shelf_life_days !== null ? (string) $item->shelf_life_days : '',
            'category' => (string) ($item->category?->name ?? ''),
            'vat_rate' => $item->vatGroup ? rtrim(rtrim(Decimal::round($item->vatGroup->percentage, 2), '0'), '.') : '0',
            'constant' => (string) ($column['constant'] ?? ''),
            default => '',
        };
    }

    private function price(ScaleExportTemplate $template, Item $item, ?ItemUom $uom): string
    {
        $price = $this->listPrice($template, $uom) ?? (string) ($uom?->selling_price ?? '0');

        return Decimal::mul($price, (string) ($template->price_multiplier ?: '1'));
    }

    /** The item's price on the template's price list (quantity 1), or null when it isn't on it. */
    private function listPrice(ScaleExportTemplate $template, ?ItemUom $uom): ?string
    {
        if ($template->price_source !== 'price_list' || $template->price_list_id === null || $uom === null) {
            return null;
        }
        $listId = (int) $template->price_list_id;
        if (! isset($this->listPrices[$listId])) {
            $prices = [];
            foreach (PriceListItem::query()->where('price_list_id', $listId)->where('min_quantity', '<=', 1)->orderBy('min_quantity')->get(['item_uom_id', 'price']) as $row) {
                $prices[(int) $row->item_uom_id] = (string) $row->price; // highest min_quantity ≤ 1 wins
            }
            $this->listPrices[$listId] = $prices;
        }

        return $this->listPrices[$listId][(int) $uom->id] ?? null;
    }

    /**
     * True when the template's price list is in another currency than the primary one. Then an item
     * missing from the list can't fall back to its selling price (that would mix currencies in one file).
     */
    private function foreignList(ScaleExportTemplate $template): bool
    {
        if ($template->price_source !== 'price_list' || $template->price_list_id === null) {
            return false;
        }
        $listCurrency = PriceList::query()->whereKey($template->price_list_id)->value('currency_id');
        $primary = CompanySetting::current()->primary_currency_id;

        return $listCurrency !== null && $primary !== null && (int) $listCurrency !== (int) $primary;
    }

    /**
     * Splits items into those the file can carry and those left out: with a price list in another
     * currency, items that have no price on it.
     *
     * @param  Collection<int, Item>  $items
     * @return array{0: Collection<int, Item>, 1: Collection<int, Item>}
     */
    private function partition(ScaleExportTemplate $template, Collection $items): array
    {
        if (! $this->foreignList($template)) {
            return [$items, new Collection];
        }
        [$kept, $missing] = $items->partition(fn (Item $item): bool => $this->listPrice($template, $this->saleUnit($item)) !== null);

        return [$kept->values(), $missing->values()];
    }

    /**
     * @param  Collection<int, Item>  $missing
     * @return array{count: int, names: list<string>}
     */
    private function missingSummary(Collection $missing): array
    {
        return [
            'count' => $missing->count(),
            'names' => $missing->take(10)->map(fn (Item $item): string => (string) $item->name)->values()->all(),
        ];
    }

    private function formatNumber(string $value, ScaleExportTemplate $template): string
    {
        $text = Decimal::round($value, max(0, min(6, (int) $template->price_decimals)));

        return $template->decimal_separator === ',' ? str_replace('.', ',', $text) : $text;
    }

    private function primaryBarcode(Item $item, ?ItemUom $uom): string
    {
        $primary = $item->barcodes->firstWhere('is_primary', true) ?? $item->barcodes->first();

        return (string) ($primary?->barcode ?? $uom?->barcode ?? '');
    }

    private function saleUnit(Item $item): ?ItemUom
    {
        $units = $item->itemUoms;

        return $units->firstWhere('is_default_sale', true) ?? $units->firstWhere('is_base', true) ?? $units->first();
    }

    /**
     * @return Builder<Item>
     */
    private function baseQuery(ScaleExportTemplate $template): Builder
    {
        $query = Item::query()
            ->where('items.is_scale_item', true)
            ->where('items.is_active', true)
            ->where('items.is_variant_parent', false)
            ->whereNotNull('items.plu_code');
        $categories = array_values(array_filter(array_map('intval', (array) ($template->category_ids ?? []))));
        if ($categories !== []) {
            $query->whereIn('items.category_id', $categories);
        }

        return $query;
    }
}
