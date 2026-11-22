<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Support;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;

/**
 * The parts of a format the parser needs. Built from a saved format or from an unsaved form
 * (the settings "Test a barcode" box), so the parser never touches the database.
 */
final readonly class ScaleFormatConfig
{
    /**
     * @param  list<string>  $prefixes
     */
    public function __construct(
        public string $engine,
        public string $symbology,
        public array $prefixes,
        public ?int $totalLength,
        public ?int $pluStart,
        public ?int $pluLength,
        public ?int $valueStart,
        public ?int $valueLength,
        public string $valueType,
        public int $decimals,
        public string $multiplier,
        public bool $verifyCheckDigit,
        public ?int $priceCheckDigitPosition,
    ) {}

    public static function fromModel(ScaleBarcodeFormat $format): self
    {
        return self::fromArray($format->only([
            'engine', 'symbology', 'prefixes', 'total_length', 'plu_start', 'plu_length',
            'value_start', 'value_length', 'value_type', 'decimals', 'multiplier',
            'verify_check_digit', 'price_check_digit_position',
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $int = static fn (mixed $v): ?int => $v === null || $v === '' ? null : (int) $v;
        $prefixes = array_values(array_filter(
            array_map(static fn ($p): string => trim((string) $p), (array) ($data['prefixes'] ?? [])),
            static fn (string $p): bool => $p !== '',
        ));

        return new self(
            engine: (string) ($data['engine'] ?? ScaleBarcodeFormat::ENGINE_FIXED),
            symbology: (string) ($data['symbology'] ?? 'ean13'),
            prefixes: $prefixes,
            totalLength: $int($data['total_length'] ?? null),
            pluStart: $int($data['plu_start'] ?? null),
            pluLength: $int($data['plu_length'] ?? null),
            valueStart: $int($data['value_start'] ?? null),
            valueLength: $int($data['value_length'] ?? null),
            valueType: (string) ($data['value_type'] ?? ScaleBarcodeFormat::TYPE_WEIGHT),
            decimals: (int) ($data['decimals'] ?? 0),
            multiplier: isset($data['multiplier']) && $data['multiplier'] !== '' ? (string) $data['multiplier'] : '1',
            verifyCheckDigit: (bool) ($data['verify_check_digit'] ?? true),
            priceCheckDigitPosition: $int($data['price_check_digit_position'] ?? null),
        );
    }
}
