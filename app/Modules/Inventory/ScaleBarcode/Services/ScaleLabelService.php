<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleLabelTemplate;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleBarcodeBuilder;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleFormatConfig;
use App\Support\Barcode\BarcodeSvg;
use App\Support\Decimal;
use App\Support\DocumentPdf\DocumentLetterhead;
use App\Support\DocumentPdf\DocumentMoney;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Weighed-item labels printed by the ERP: one per page on a label printer, or a grid on A4.
 * The barcode is built in the template's scale format, so it scans back to the same item and value.
 */
class ScaleLabelService
{
    /** Points per millimetre (dompdf paper sizes are in points). */
    private const PT_PER_MM = 2.834645669;

    /** Most labels one request may print (dompdf keeps every label in memory). */
    public const MAX_LABELS = 500;

    /** Memory for one print: about 0.5 MB per label with its barcode. */
    private const PRINT_MEMORY = '512M';

    public function __construct(
        private readonly DocumentLetterhead $letterhead,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines  item_id, quantity?, total?, copies?, packed_date?
     */
    public function render(ScaleLabelTemplate $template, array $lines): DomPdf
    {
        $arabic = app()->getLocale() === 'ar';
        $labels = $this->labels($template, $lines, $arabic);
        $this->raiseMemoryLimit();
        $width = (float) $template->width_mm;
        $height = (float) $template->height_mm;

        $view = [
            'template' => $template,
            'labels' => $labels,
            'fields' => array_flip((array) $template->fields),
            'company' => $this->letterhead->resolve($arabic)['company_name'],
            'captions' => $this->captions($arabic),
            'width' => $width,
            'height' => $height,
            'fontSize' => max(5, (int) $template->font_size),
        ];

        if ($template->mode === 'sheet') {
            $columns = max(1, (int) $template->sheet_columns);
            $rows = max(1, (int) $template->sheet_rows);
            $margin = (float) $template->margin_mm;
            $gap = (float) $template->gap_mm;
            $pages = array_chunk($labels, $columns * $rows);
            $positions = [];
            for ($i = 0; $i < $columns * $rows; $i++) {
                $positions[] = [
                    'left' => $margin + ($i % $columns) * ($width + $gap),
                    'top' => $margin + intdiv($i, $columns) * ($height + $gap),
                ];
            }

            return Pdf::loadView('scale-labels.sheet', [...$view, 'pages' => $pages, 'positions' => $positions])
                ->setPaper('a4', 'portrait');
        }

        return Pdf::loadView('scale-labels.single', $view)
            ->setPaper([0, 0, $width * self::PT_PER_MM, $height * self::PT_PER_MM]);
    }

    /**
     * A label for the preview when no line is given: the first scale item, 1.250 (or 1).
     *
     * @return list<array<string, mixed>>
     */
    public function sampleLines(ScaleLabelTemplate $template): array
    {
        $item = Item::query()->where('is_scale_item', true)->where('is_active', true)->whereNotNull('plu_code')->orderBy('name')->first();
        if ($item === null) {
            abort(422, 'Mark an item as sold at the scale (with a PLU) to preview a label.', ['X-Error-Code' => 'SCALE_LABEL_NO_SAMPLE_ITEM']);
        }
        $type = $template->barcodeFormat?->value_type;

        return [[
            'item_id' => (string) $item->id,
            'quantity' => $type === ScaleBarcodeFormat::TYPE_COUNT ? '1' : '1.25',
            'copies' => 1,
        ]];
    }

    /**
     * One entry per printed label (copies expanded).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function labels(ScaleLabelTemplate $template, array $lines, bool $arabic = false): array
    {
        $total = array_sum(array_map(fn (array $l): int => max(1, (int) ($l['copies'] ?? 1)), $lines));
        if ($total > self::MAX_LABELS) {
            abort(422, 'At most '.self::MAX_LABELS.' labels can be printed at once.', [
                'X-Error-Code' => 'SCALE_LABEL_TOO_MANY',
                'X-Error-Params' => (string) json_encode(['max' => self::MAX_LABELS]),
            ]);
        }

        // Templates load a slim format for lists; the barcode needs every position.
        $format = $template->scale_barcode_format_id !== null ? ScaleBarcodeFormat::query()->find($template->scale_barcode_format_id) : null;
        $config = $format ? ScaleFormatConfig::fromModel($format) : null;
        $money = new DocumentMoney($arabic);
        // Label prices are the item's selling price, so they are in the primary currency.
        $symbol = trim((string) (CompanySetting::current()->primaryCurrency?->symbol ?? ''));
        /** @var Collection<string, Item> $items */
        $items = Item::query()
            ->with(['itemUoms.uom:id,code,name', 'barcodes'])
            ->whereIn('id', array_map(fn (array $l): string => (string) $l['item_id'], $lines))
            ->get()
            ->keyBy(fn (Item $item): string => (string) $item->id);

        $out = [];
        foreach ($lines as $line) {
            $item = $items->get((string) $line['item_id']);
            if ($item === null) {
                continue;
            }
            $label = $this->label($item, $line, $format, $config, $money, $symbol);
            for ($i = 0, $copies = max(1, (int) ($line['copies'] ?? 1)); $i < $copies; $i++) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function label(Item $item, array $line, ?ScaleBarcodeFormat $format, ?ScaleFormatConfig $config, DocumentMoney $money, string $symbol = ''): array
    {
        $withSymbol = fn (string $amount): string => $symbol !== '' ? $money->money($amount).' '.$money->text($symbol) : $money->money($amount);
        $uom = $this->saleUnit($item);
        $unitPrice = (string) ($uom?->selling_price ?? '0');
        $unitCode = (string) ($uom?->uom?->code ?? '');
        $quantity = isset($line['quantity']) && $line['quantity'] !== '' && $line['quantity'] !== null ? Decimal::of($line['quantity']) : null;
        $total = isset($line['total']) && $line['total'] !== '' && $line['total'] !== null ? Decimal::of($line['total']) : null;
        if ($quantity === null && $total !== null && Decimal::isPositive($unitPrice)) {
            $quantity = Decimal::round(Decimal::div($total, $unitPrice), 3);
        }
        if ($quantity === null) {
            abort(422, 'Enter the weight, count or price of each label.', ['X-Error-Code' => 'SCALE_LABEL_VALUE_REQUIRED']);
        }
        $total ??= Decimal::round(Decimal::mul($quantity, $unitPrice), 6);

        $packed = isset($line['packed_date']) && $line['packed_date'] ? Carbon::parse((string) $line['packed_date']) : now();
        $useBy = $item->shelf_life_days !== null ? $packed->copy()->addDays((int) $item->shelf_life_days) : null;

        $barcode = null;
        if ($format !== null && $config !== null) {
            $value = match ($format->value_type) {
                ScaleBarcodeFormat::TYPE_PRICE => $total,
                default => $quantity,
            };
            $gtin = ($item->barcodes->firstWhere('is_primary', true) ?? $item->barcodes->first())?->barcode;
            $built = ScaleBarcodeBuilder::build(
                $config,
                (string) $item->plu_code,
                $value,
                gtin: $gtin !== null ? (string) $gtin : null,
                weight: $format->value_type === ScaleBarcodeFormat::TYPE_WEIGHT ? $quantity : null,
                price: $config->engine === ScaleBarcodeFormat::ENGINE_GS1 ? $total : null,
                useBy: $useBy?->toDateString(),
            );
            $barcode = [
                'image' => BarcodeSvg::dataUri($built['symbology'], $built['data'], 60),
                'text' => $built['text'],
                'data' => $built['data'],
            ];
        }

        return [
            'item_id' => (string) $item->id,
            'name' => $money->text($item->name),
            'second_name' => $money->text($item->pos_name ?: null),
            'plu' => (string) $item->plu_code,
            'unit_code' => $money->text($unitCode),
            'unit_price' => $withSymbol($unitPrice),
            'quantity' => $money->quantity($quantity),
            'quantity_raw' => $quantity,
            'total' => $withSymbol($total),
            'total_raw' => $total,
            'packed_date' => $money->date($packed),
            'use_by' => $useBy ? $money->date($useBy) : null,
            'use_by_raw' => $useBy?->toDateString(),
            'barcode' => $barcode,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function captions(bool $arabic): array
    {
        $captions = $arabic
            ? ['unit_price' => 'السعر', 'value' => 'الكمية', 'total' => 'المجموع', 'packed_date' => 'تاريخ التعبئة', 'use_by' => 'يستهلك قبل', 'plu' => 'PLU']
            : ['unit_price' => 'Price', 'value' => 'Qty', 'total' => 'Total', 'packed_date' => 'Packed', 'use_by' => 'Use by', 'plu' => 'PLU'];

        return $arabic ? array_map(fn (string $c): string => (new DocumentMoney(true))->text($c), $captions) : $captions;
    }

    private function saleUnit(Item $item): ?ItemUom
    {
        $units = $item->itemUoms;

        return $units->firstWhere('is_default_sale', true) ?? $units->firstWhere('is_base', true) ?? $units->first();
    }

    private function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current === '-1') {
            return;
        }
        $bytes = static function (string $value): int {
            $number = (int) $value;

            return match (strtoupper(substr(trim($value), -1))) {
                'G' => $number * 1024 ** 3,
                'M' => $number * 1024 ** 2,
                'K' => $number * 1024,
                default => $number,
            };
        };
        if ($bytes($current) < $bytes(self::PRINT_MEMORY)) {
            ini_set('memory_limit', self::PRINT_MEMORY);
        }
    }
}
