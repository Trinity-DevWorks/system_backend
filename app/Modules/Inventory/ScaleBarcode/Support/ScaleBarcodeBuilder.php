<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Support;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use App\Support\Barcode\BarcodeSvg;
use App\Support\Decimal;

/**
 * Builds the barcode a label carries, in one format: the mirror of ScaleBarcodeParser, so every code
 * the ERP prints scans back to the same item and value. Pure: no database.
 *
 * Returns ['data' => what the bars encode (FNC1 = \x1D), 'text' => what is printed under the bars].
 */
final class ScaleBarcodeBuilder
{
    /**
     * @param  string  $plu  the item's PLU (digits)
     * @param  string|null  $gtin  the item's own barcode, used by GS1 formats when it is a valid GTIN
     * @param  string|null  $weight  kg, for GS1 (written as 310n)
     * @param  string|null  $price  label total, for GS1 (written as 392n)
     * @param  string|null  $useBy  Y-m-d, for GS1 (written as 17)
     * @return array{data: string, text: string, symbology: string}
     */
    public static function build(
        ScaleFormatConfig $format,
        string $plu,
        string $value,
        ?string $gtin = null,
        ?string $weight = null,
        ?string $price = null,
        ?string $useBy = null,
    ): array {
        return $format->engine === ScaleBarcodeFormat::ENGINE_GS1
            ? self::buildGs1($format, $plu, $value, $gtin, $weight, $price, $useBy)
            : self::buildFixed($format, $plu, $value);
    }

    /**
     * @return array{data: string, text: string, symbology: string}
     */
    private static function buildFixed(ScaleFormatConfig $format, string $plu, string $value): array
    {
        if ($format->pluStart === null || $format->pluLength === null || $format->valueStart === null || $format->valueLength === null) {
            self::fail('SCALE_LABEL_FORMAT_INCOMPLETE', 'This barcode format has no PLU or value positions, so it can\'t be printed.');
        }
        $numeric = in_array($format->symbology, ScaleBarcodeFormat::MOD10_SYMBOLOGIES, true);
        $length = $format->totalLength ?? match ($format->symbology) {
            'ean13' => 13,
            'upca' => 12,
            default => max(
                $format->pluStart + $format->pluLength - 1,
                $format->valueStart + $format->valueLength - 1,
                (int) $format->priceCheckDigitPosition,
            ),
        };
        $code = str_repeat('0', $length);

        $prefix = $format->prefixes[0] ?? '';
        $code = self::place($code, 1, $prefix);
        $code = self::place($code, $format->pluStart, self::fit(self::pluDigits($plu), $format->pluLength, 'SCALE_LABEL_PLU_TOO_LONG', ['plu' => $plu, 'digits' => $format->pluLength]));
        $valueDigits = self::valueDigits($value, $format->decimals, $format->multiplier);
        $valueDigits = self::fit($valueDigits, $format->valueLength, 'SCALE_LABEL_VALUE_TOO_BIG', ['value' => $value, 'digits' => $format->valueLength]);
        $code = self::place($code, $format->valueStart, $valueDigits);
        if ($format->priceCheckDigitPosition !== null) {
            $check = CheckDigit::price($valueDigits);
            if ($check !== null) {
                $code = self::place($code, $format->priceCheckDigitPosition, $check);
            }
        }
        if ($numeric) {
            $code = substr($code, 0, -1).CheckDigit::mod10(substr($code, 0, -1));
        }

        return ['data' => $code, 'text' => $code, 'symbology' => $numeric ? $format->symbology : 'code128'];
    }

    /**
     * @return array{data: string, text: string, symbology: string}
     */
    private static function buildGs1(ScaleFormatConfig $format, string $plu, string $value, ?string $gtin, ?string $weight, ?string $price, ?string $useBy): array
    {
        $gtin14 = self::gtin14($format, $plu, $gtin);
        $fixed = ['01' => $gtin14];
        $variable = [];

        // GS1 carries its own decimal count in the AI (310n / 392n), so the format's decimals don't
        // matter here: write as many decimals as fit, so 1.25 kg is never rounded to 1 kg.
        $weight ??= $format->valueType === ScaleBarcodeFormat::TYPE_WEIGHT ? $value : null;
        $price ??= $format->valueType === ScaleBarcodeFormat::TYPE_PRICE ? $value : null;
        if ($weight !== null) {
            [$n, $digits] = self::gs1Decimals($weight, 3, 6);
            $fixed['310'.$n] = str_pad($digits, 6, '0', STR_PAD_LEFT);
        }
        if ($useBy !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $useBy) === 1) {
            $fixed['17'] = substr(str_replace('-', '', $useBy), 2);
        }
        if ($format->valueType === ScaleBarcodeFormat::TYPE_COUNT) {
            $variable['30'] = ltrim(self::fit(self::valueDigits($value, 0, '1'), 8, 'SCALE_LABEL_VALUE_TOO_BIG', ['value' => $value, 'digits' => 8]), '0') ?: '0';
        }
        if ($price !== null) {
            [$n, $digits] = self::gs1Decimals($price, 2, 15);
            $variable['392'.$n] = $digits;
        }

        $data = BarcodeSvg::FNC1;
        $text = '';
        foreach ($fixed as $ai => $digits) {
            $data .= $ai.$digits;
            $text .= '('.$ai.')'.$digits;
        }
        $last = array_key_last($variable);
        foreach ($variable as $ai => $digits) {
            $data .= $ai.$digits.($ai === $last ? '' : BarcodeSvg::FNC1);
            $text .= '('.$ai.')'.$digits;
        }

        return ['data' => $data, 'text' => $text, 'symbology' => 'gs1_128'];
    }

    /** The item's own GTIN when valid, else one built from the format's prefix and PLU positions. */
    private static function gtin14(ScaleFormatConfig $format, string $plu, ?string $gtin): string
    {
        $gtin = $gtin !== null ? trim($gtin) : '';
        if (in_array(strlen($gtin), [8, 12, 13, 14], true) && CheckDigit::isValidMod10($gtin)) {
            return str_pad($gtin, 14, '0', STR_PAD_LEFT);
        }
        if ($format->pluStart === null || $format->pluLength === null || $format->pluStart + $format->pluLength - 1 > 13) {
            self::fail('SCALE_LABEL_NO_GTIN', 'This item has no GTIN barcode, and the format has no PLU position inside the GTIN to build one.');
        }
        $code = self::place(str_repeat('0', 13), 1, $format->prefixes[0] ?? '');
        $code = self::place($code, $format->pluStart, self::fit(self::pluDigits($plu), $format->pluLength, 'SCALE_LABEL_PLU_TOO_LONG', ['plu' => $plu, 'digits' => $format->pluLength]));

        return $code.CheckDigit::mod10($code);
    }

    /**
     * The most decimals (up to $max) that keep $value within $digits digits.
     *
     * @return array{0: int, 1: string} [decimals, digits without leading zeros]
     */
    private static function gs1Decimals(string $value, int $max, int $digits): array
    {
        for ($n = $max; $n >= 0; $n--) {
            $raw = ltrim(self::valueDigits($value, $n, '1'), '0') ?: '0';
            if (strlen($raw) <= $digits) {
                return [$n, $raw];
            }
        }
        self::fail('SCALE_LABEL_VALUE_TOO_BIG', 'The value '.$value.' doesn\'t fit the '.$digits.' digits of this format.', ['value' => $value, 'digits' => $digits]);
    }

    /** value ÷ multiplier × 10^decimals, rounded to a whole number of digits. */
    public static function valueDigits(string $value, int $decimals, string $multiplier): string
    {
        $multiplier = Decimal::isZero($multiplier) ? '1' : $multiplier;
        $raw = Decimal::mul(Decimal::div($value, $multiplier), bcpow('10', (string) max(0, $decimals)));
        if (Decimal::isNegative($raw)) {
            self::fail('SCALE_LABEL_VALUE_TOO_BIG', 'A label value can\'t be negative.', ['value' => $value, 'digits' => 0]);
        }

        return Decimal::round($raw, 0);
    }

    private static function pluDigits(string $plu): string
    {
        $plu = trim($plu);
        if (preg_match('/^\d+$/', $plu) !== 1) {
            self::fail('SCALE_LABEL_PLU_TOO_LONG', 'The item\'s PLU must be digits to print a scale label.', ['plu' => $plu, 'digits' => 0]);
        }

        return $plu;
    }

    /**
     * Left-pads with zeros to $length; refuses when the number needs more digits.
     *
     * @param  array<string, mixed>  $params
     */
    private static function fit(string $digits, int $length, string $code, array $params): string
    {
        $trimmed = ltrim($digits, '0');
        if (strlen($trimmed) > $length) {
            self::fail($code, $code === 'SCALE_LABEL_PLU_TOO_LONG'
                ? 'PLU '.$params['plu'].' doesn\'t fit the '.$length.' PLU digits of this format.'
                : 'The value '.$params['value'].' doesn\'t fit the '.$length.' digits of this format.', $params);
        }

        return str_pad($trimmed, $length, '0', STR_PAD_LEFT);
    }

    /** Writes $part at 1-based $start. */
    private static function place(string $code, int $start, string $part): string
    {
        if ($part === '') {
            return $code;
        }

        return substr($code, 0, $start - 1).$part.substr($code, $start - 1 + strlen($part));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function fail(string $code, string $message, array $params = []): never
    {
        abort(422, $message, [
            'X-Error-Code' => $code,
            'X-Error-Params' => (string) json_encode($params),
        ]);
    }
}
