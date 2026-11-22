<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Support;

/**
 * What a scale label says, before it is matched to an item.
 */
final readonly class ParsedScaleBarcode
{
    public const UNIT_KG = 'kg';

    public const UNIT_LB = 'lb';

    /**
     * @param  string|null  $plu  digits identifying the item (fixed layouts, or a GTIN slice)
     * @param  string|null  $gtin  GS1 AI 01, when present
     * @param  string  $valueType  weight | price | count (what the format reads)
     * @param  string  $value  decimal string of that value (format unit, price, or pieces)
     * @param  string|null  $currencyIsoNumeric  ISO 4217 numeric code from AI 393n
     * @param  string  $rawValue  the digits as printed
     * @param  string|null  $weight  GS1 only: net weight from 310n / 320n
     * @param  string|null  $weightUnit  GS1 only: kg (310n) or lb (320n)
     * @param  string|null  $price  GS1 only: price from 392n / 393n
     * @param  string|null  $lot  GS1 only: AI 10
     * @param  string|null  $expiry  GS1 only: AI 17 as Y-m-d
     * @param  string|null  $bestBefore  GS1 only: AI 15 as Y-m-d
     * @param  array<string, string>  $weights  GS1 only: every weight on the label, kg / lb => value
     */
    public function __construct(
        public ?string $plu,
        public ?string $gtin,
        public string $valueType,
        public string $value,
        public ?string $currencyIsoNumeric,
        public string $rawValue,
        public ?string $weight = null,
        public ?string $weightUnit = null,
        public ?string $price = null,
        public ?string $lot = null,
        public ?string $expiry = null,
        public ?string $bestBefore = null,
        public array $weights = [],
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'plu' => $this->plu,
            'gtin' => $this->gtin,
            'value_type' => $this->valueType,
            'value' => $this->value,
            'raw_value' => $this->rawValue,
            'currency_iso_numeric' => $this->currencyIsoNumeric,
            'weight' => $this->weight,
            'weight_unit' => $this->weightUnit,
            'price' => $this->price,
            'lot' => $this->lot,
            'expiry' => $this->expiry,
            'best_before' => $this->bestBefore,
        ];
    }
}
