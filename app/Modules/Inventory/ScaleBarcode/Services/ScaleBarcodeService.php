<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\DTOs\ItemResponseData;
use App\Modules\Inventory\Item\DTOs\ItemUomResponseData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use App\Modules\Inventory\ScaleBarcode\Support\ParsedScaleBarcode;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleBarcodeParser;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleFormatConfig;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Services\InventoryLotService;
use App\Support\Decimal;
use App\Support\DocumentTaxContext;
use App\Support\TenantClock;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Turns a scanned scale label into an item line: which item (PLU or GTIN), which unit, the
 * quantity, and — for price labels — the price the label charges.
 *
 * Rules (plans/scale-barcodes.md): exact barcodes are tried before this; formats are tried in
 * priority order; a label whose PLU is unknown is an error, never a guess.
 */
class ScaleBarcodeService
{
    /** International pound, exact. */
    private const KG_PER_LB = '0.45359237';

    /** @var array<string, Collection<int, ScaleBarcodeFormat>> */
    private array $formatsCache = [];

    public function __construct(
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * Active formats for a branch (formats with no branches apply everywhere), in priority order.
     *
     * @return Collection<int, ScaleBarcodeFormat>
     */
    public function formatsFor(?int $branchId): Collection
    {
        $key = (string) ($branchId ?? 'all');

        return $this->formatsCache[$key] ??= ScaleBarcodeFormat::query()
            ->with(['uom:id,code,name', 'currency:id,code,iso_code,name'])
            ->where('is_active', true)
            ->where(function ($query) use ($branchId): void {
                $query->whereDoesntHave('branches');
                if ($branchId !== null) {
                    $query->orWhereHas('branches', fn ($q) => $q->whereKey($branchId));
                }
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * The lookup result for a scale label, or null when no format recognises the string.
     * Throws (422 / 404 with an error code) when a format matches but the label can't be used.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $raw, ?int $branchId): ?array
    {
        foreach ($this->formatsFor($branchId) as $format) {
            $parsed = ScaleBarcodeParser::parse($raw, ScaleFormatConfig::fromModel($format));
            if ($parsed !== null) {
                return $this->line($format, $parsed);
            }
        }

        return null;
    }

    /**
     * Settings "Test a barcode": parse with an unsaved format and show what the line would be.
     *
     * @param  array<string, mixed>  $formatData
     * @return array<string, mixed>
     */
    public function test(array $formatData, string $raw): array
    {
        $parsed = ScaleBarcodeParser::parse($raw, ScaleFormatConfig::fromArray($formatData));
        if ($parsed === null) {
            return ['matched' => false];
        }

        $format = new ScaleBarcodeFormat($formatData);
        $format->name = (string) ($formatData['name'] ?? '');

        try {
            $line = $this->line($format, $parsed);
        } catch (HttpException $e) {
            return [
                'matched' => true,
                'parsed' => $parsed->toArray(),
                'error' => [
                    'code' => $e->getHeaders()['X-Error-Code'] ?? null,
                    'message' => $e->getMessage(),
                    'params' => json_decode((string) ($e->getHeaders()['X-Error-Params'] ?? 'null'), true),
                ],
            ];
        }

        return ['matched' => true, 'parsed' => $parsed->toArray(), 'line' => $line];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(ScaleBarcodeFormat $format, ParsedScaleBarcode $parsed): array
    {
        $item = $this->findItem($parsed);
        $value = $parsed->value;
        /** @var list<array{code: string, params: array<string, string>}> $warnings */
        $warnings = [];
        $this->checkDates($parsed, $warnings);

        // A GS1 weight says its own unit (310n kg, 320n lb); otherwise the format's unit is used.
        $weightQuantity = null;
        if ($parsed->weight !== null && $parsed->weightUnit !== null) {
            $weights = $parsed->weights !== [] ? $parsed->weights : [$parsed->weightUnit => $parsed->weight];
            $this->assertWeightsAgree($weights);
            [$itemUom, $weightQuantity] = $this->weightInItemUnit($item, $weights);
        } else {
            $itemUom = $this->itemUom($format, $item);
        }

        $labelPrice = $parsed->valueType === ScaleBarcodeFormat::TYPE_PRICE ? $value : $parsed->price;
        $embeddedPrice = null;
        $embeddedCurrency = null;
        $embeddedPrimary = null;
        $unitPricePrimary = null;
        $priceSource = 'catalog';

        if ($labelPrice !== null) {
            $currency = $this->labelCurrency($format, $parsed);
            $embeddedPrice = $labelPrice;
            $embeddedCurrency = $currency?->code;
            $primaryPrice = $this->toPrimary($labelPrice, $currency);
            $primaryPrice = $this->matchTaxMode($primaryPrice, (bool) ($format->price_includes_tax ?? true), $item);
            $embeddedPrimary = Decimal::round($primaryPrice, 6);
            $itemPrice = (string) ($itemUom->selling_price ?? '0');
            $priceSource = 'scale';

            if ($weightQuantity !== null) {
                // Weight and price on one label: the weight is the quantity, the label sets the price.
                $quantity = Decimal::round($weightQuantity, 6);
                if (! Decimal::isPositive($quantity)) {
                    self::fail(422, 'The label weight is zero.', 'SCALE_BARCODE_ZERO_VALUE', []);
                }
                $unitPricePrimary = Decimal::round(Decimal::div($primaryPrice, $quantity), 6);
                if (Decimal::isPositive($itemPrice) && $this->differsByMoreThan($unitPricePrimary, $itemPrice, '0.01')) {
                    $warnings[] = ['code' => 'SCALE_LABEL_PRICE_DIFFERS', 'params' => [
                        'label_price' => Decimal::round($unitPricePrimary, 2),
                        'item_price' => Decimal::round($itemPrice, 2),
                        'unit' => (string) ($itemUom->uom?->code ?? ''),
                    ]];
                }
            } else {
                if (! Decimal::isPositive($itemPrice)) {
                    self::fail(422, 'Set a selling price for '.$item->name.' to sell it from a price label.', 'SCALE_BARCODE_NO_UNIT_PRICE', ['item' => $item->name]);
                }
                // Stock still goes down by weight: quantity = label price ÷ unit price.
                $quantity = Decimal::round(Decimal::div($primaryPrice, $itemPrice), 6);
            }
        } elseif ($weightQuantity !== null) {
            $quantity = Decimal::round($weightQuantity, 6);
        } else {
            $quantity = Decimal::round($value, 6);
        }

        if (! Decimal::isPositive($quantity)) {
            self::fail(422, 'The label quantity is zero.', 'SCALE_BARCODE_ZERO_VALUE', []);
        }

        $lot = $this->labelLot($item, $parsed);

        return [
            'source' => 'scale',
            'barcode_record_id' => null,
            'item' => ItemResponseData::fromModel($item)->toArray(),
            'item_uom' => ItemUomResponseData::fromModel($itemUom),
            'scale' => [
                'format_id' => $format->id,
                'format_name' => $format->name,
                'value_type' => $parsed->valueType,
                'value' => $value,
                'raw_value' => $parsed->rawValue,
                'plu' => $parsed->plu,
                'gtin' => $parsed->gtin,
                'quantity' => $quantity,
                'unit' => $itemUom->uom?->code,
                'embedded_price' => $embeddedPrice,
                'embedded_currency' => $embeddedCurrency,
                'embedded_price_primary' => $embeddedPrimary,
                // Set when the label has both weight and price: the price per unit the label charges.
                'unit_price_primary' => $unitPricePrimary,
                'price_source' => $priceSource,
                'weight' => $parsed->weight,
                'weight_unit' => $parsed->weightUnit,
                'lot_id' => $lot?->id,
                'lot_number' => $lot?->lot_number ?? $parsed->lot,
                'expiry' => $parsed->expiry,
                'best_before' => $parsed->bestBefore,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * An expired label can't be sold; a passed best-before date only warns.
     *
     * @param  list<array{code: string, params: array<string, string>}>  $warnings
     */
    private function checkDates(ParsedScaleBarcode $parsed, array &$warnings): void
    {
        $today = TenantClock::today()->toDateString();
        if ($parsed->expiry !== null && $parsed->expiry < $today) {
            self::fail(422, 'This label expired on '.$parsed->expiry.'.', 'SCALE_BARCODE_EXPIRED', ['date' => $parsed->expiry]);
        }
        if ($parsed->bestBefore !== null && $parsed->bestBefore < $today) {
            $warnings[] = ['code' => 'SCALE_LABEL_BEST_BEFORE_PASSED', 'params' => ['date' => $parsed->bestBefore]];
        }
    }

    /**
     * A label printing its weight in both kg and lb must say the same thing (0.5% tolerance).
     *
     * @param  array<string, string>  $weights
     */
    private function assertWeightsAgree(array $weights): void
    {
        $kg = $weights[ParsedScaleBarcode::UNIT_KG] ?? null;
        $lb = $weights[ParsedScaleBarcode::UNIT_LB] ?? null;
        if ($kg === null || $lb === null) {
            return;
        }
        $lbInKg = Decimal::mul($lb, self::KG_PER_LB);
        $tolerance = Decimal::max(Decimal::mul($kg, '0.005'), '0.001');
        if (Decimal::cmp(Decimal::abs(Decimal::sub($kg, $lbInKg)), $tolerance) > 0) {
            self::fail(422, 'The label weights disagree: '.Decimal::round($kg, 3).' kg and '.Decimal::round($lb, 3).' lb.', 'SCALE_BARCODE_WEIGHTS_DISAGREE', [
                'kg' => Decimal::round($kg, 3),
                'lb' => Decimal::round($lb, 3),
            ]);
        }
    }

    /**
     * The item unit for a GS1 weight: the item's KG or LB unit, using the label weight printed in that
     * unit when there is one, otherwise converting (1 lb = 0.45359237 kg) and rounding to the unit's
     * decimals, as a scale would print it.
     *
     * @param  array<string, string>  $weights  kg / lb => value (at least one)
     * @return array{0: ItemUom, 1: string}
     */
    private function weightInItemUnit(Item $item, array $weights): array
    {
        $units = ItemUom::query()
            ->where('item_id', $item->id)
            ->with(['uom:id,code,name,unit_group_id,decimal_places'])
            ->get()
            ->keyBy(fn (ItemUom $row): string => strtoupper((string) $row->uom?->code));

        $codes = [ParsedScaleBarcode::UNIT_KG => 'KG', ParsedScaleBarcode::UNIT_LB => 'LB'];
        foreach ($weights as $unit => $value) {
            if ($units->has($codes[$unit])) {
                return [$units->get($codes[$unit]), $value];
            }
        }
        $first = (string) array_key_first($weights);
        $value = $weights[$first];
        $target = $first === ParsedScaleBarcode::UNIT_KG ? 'LB' : 'KG';
        if ($units->has($target)) {
            /** @var ItemUom $uom */
            $uom = $units->get($target);
            $converted = $target === 'KG'
                ? Decimal::mul($value, self::KG_PER_LB)
                : Decimal::div($value, self::KG_PER_LB);
            $decimals = (int) ($uom->uom?->decimal_places ?? 3);

            return [$uom, Decimal::round($converted, max(0, min(6, $decimals)))];
        }

        $missing = $codes[$first];
        self::fail(422, $item->name.' has no '.$missing.' unit. Add it to the item to sell it from this label.', 'SCALE_BARCODE_ITEM_UNIT_MISSING', [
            'item' => $item->name,
            'unit' => $missing,
        ]);
    }

    /** The lot printed on the label (AI 10), for items that track lots. Unknown lots are refused. */
    private function labelLot(Item $item, ParsedScaleBarcode $parsed): ?InventoryLot
    {
        if ($parsed->lot === null || $parsed->lot === '' || ! $item->track_lots) {
            return null;
        }
        $number = InventoryLotService::normalizeLotNumber($parsed->lot);
        $lot = InventoryLot::query()->where('item_id', $item->id)->where('lot_number', $number)->first();
        if (! $lot instanceof InventoryLot) {
            self::fail(422, 'Lot '.$number.' of '.$item->name.' is not in stock records.', 'SCALE_BARCODE_UNKNOWN_LOT', [
                'lot' => (string) $number,
                'item' => $item->name,
            ]);
        }

        return $lot;
    }

    private function differsByMoreThan(string $a, string $b, string $ratio): bool
    {
        $diff = Decimal::abs(Decimal::sub($a, $b));

        return Decimal::cmp($diff, Decimal::mul($b, $ratio)) > 0;
    }

    private function findItem(ParsedScaleBarcode $parsed): Item
    {
        $item = null;
        if ($parsed->plu !== null) {
            $item = $this->scaleItemByPlu($parsed->plu);
        } elseif ($parsed->gtin !== null) {
            // 1. The GTIN as an item barcode (always wins).
            // 2. Otherwise the PLU padded inside the GTIN: drop its check digit and leading zeros.
            $item = $this->itemByGtin($parsed->gtin)
                ?? $this->scaleItemByPlu(substr($parsed->gtin, 0, -1));
        }

        if (! $item instanceof Item) {
            $label = $parsed->plu ?? $parsed->gtin ?? '';
            self::fail(404, 'No item sold at the scale has PLU '.$label.'.', 'SCALE_BARCODE_UNKNOWN_PLU', ['plu' => $label]);
        }
        if (! $item->allow_sale) {
            self::fail(422, $item->name.' is not sold.', 'SCALE_BARCODE_ITEM_NOT_SOLD', ['item' => $item->name]);
        }

        return $item;
    }

    /** An active item sold at the scale whose PLU equals these digits (leading zeros ignored). */
    private function scaleItemByPlu(string $digits): ?Item
    {
        $plu = ltrim($digits, '0');
        if ($plu === '') {
            return null;
        }

        return Item::query()
            ->where('is_scale_item', true)
            ->where('is_active', true)
            ->where('is_variant_parent', false)
            ->whereRaw("ltrim(plu_code, '0') = ?", [$plu])
            ->first();
    }

    /** A GS1 GTIN-14 matched against the item barcodes (as GTIN-14, EAN-13 or without leading zeros). */
    private function itemByGtin(string $gtin): ?Item
    {
        $candidates = array_values(array_unique(array_filter([
            $gtin,
            str_starts_with($gtin, '0') ? substr($gtin, 1) : null,
            ltrim($gtin, '0'),
        ])));

        $barcode = ItemBarcode::query()->whereIn('barcode', $candidates)->with('item')->first();
        if ($barcode?->item instanceof Item && $barcode->item->is_active) {
            return $barcode->item;
        }
        $uom = ItemUom::query()->whereIn('barcode', $candidates)->with('item')->first();

        return $uom?->item instanceof Item && $uom->item->is_active ? $uom->item : null;
    }

    private function itemUom(ScaleBarcodeFormat $format, Item $item): ItemUom
    {
        $query = fn () => ItemUom::query()->where('item_id', $item->id)->with(['uom:id,code,name,unit_group_id']);

        if ($format->uom_id !== null) {
            $match = $query()->where('uom_id', $format->uom_id)->first();
            if ($match instanceof ItemUom) {
                return $match;
            }
            // Weight / count labels must be read in the format's unit.
            if ($format->value_type !== ScaleBarcodeFormat::TYPE_PRICE) {
                $format->loadMissing('uom:id,code,name');
                $unit = (string) ($format->uom?->code ?? $format->uom_id);
                self::fail(422, $item->name.' has no '.$unit.' unit. Add it to the item to sell it from this label.', 'SCALE_BARCODE_ITEM_UNIT_MISSING', [
                    'item' => $item->name,
                    'unit' => $unit,
                ]);
            }
        }

        $default = $query()->where('is_default_sale', true)->first() ?? $query()->where('is_base', true)->first();
        if (! $default instanceof ItemUom) {
            self::fail(422, $item->name.' has no sale unit.', 'SCALE_BARCODE_ITEM_UNIT_MISSING', ['item' => $item->name, 'unit' => '']);
        }

        return $default;
    }

    private function labelCurrency(ScaleBarcodeFormat $format, ParsedScaleBarcode $parsed): ?Currency
    {
        if ($parsed->currencyIsoNumeric !== null) {
            $alpha = config('iso4217_numeric.'.$parsed->currencyIsoNumeric);
            $currency = is_string($alpha)
                ? Currency::query()->where('iso_code', $alpha)->orWhere('code', $alpha)->first()
                : null;
            if (! $currency instanceof Currency) {
                self::fail(422, 'The label currency ('.$parsed->currencyIsoNumeric.') is not set up.', 'SCALE_BARCODE_UNKNOWN_CURRENCY', ['currency' => $alpha ?? $parsed->currencyIsoNumeric]);
            }

            return $currency;
        }

        return $format->currency_id !== null ? Currency::query()->find($format->currency_id) : null;
    }

    /** Label price → primary currency ("1 primary = rate × currency"). */
    private function toPrimary(string $amount, ?Currency $currency): string
    {
        $primary = Currency::getPrimary();
        if (! $currency instanceof Currency || ! $primary instanceof Currency || (int) $currency->id === (int) $primary->id) {
            return $amount;
        }
        $rate = $this->exchangeRates->getRateById((int) $primary->id, (int) $currency->id);
        if (! Decimal::isPositive($rate)) {
            self::fail(422, 'No exchange rate to '.$currency->code.'.', 'SCALE_BARCODE_NO_RATE', ['currency' => (string) $currency->code]);
        }

        return Decimal::div($amount, $rate);
    }

    /** The label price follows the company's price mode (with or without tax). */
    private function matchTaxMode(string $amount, bool $labelIncludesTax, Item $item): string
    {
        $companyIncludesTax = DocumentTaxContext::pricesIncludeTax();
        if ($labelIncludesTax === $companyIncludesTax) {
            return $amount;
        }
        $rate = DocumentTaxContext::ratePercentForItem($item, null, TenantClock::today()->toDateString());
        if (! Decimal::isPositive($rate)) {
            return $amount;
        }
        $factor = Decimal::add('1', Decimal::div($rate, '100'));

        return $labelIncludesTax ? Decimal::div($amount, $factor) : Decimal::mul($amount, $factor);
    }

    /**
     * @param  array<string, string>  $params
     */
    private static function fail(int $status, string $message, string $code, array $params): never
    {
        throw new HttpException($status, $message, null, [
            'X-Error-Code' => $code,
            'X-Error-Params' => (string) json_encode($params),
        ]);
    }
}
