<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Support;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use App\Support\Decimal;

/**
 * Reads a scanned string with one format. Returns null when the string is not that format
 * (wrong length, prefix, check digit…) — the caller then tries the next format. Pure: no database.
 */
final class ScaleBarcodeParser
{
    /** Scanner symbology identifiers (AIM), e.g. "]E0" EAN-13, "]C1" GS1-128, "]e0" DataBar. */
    private const AIM_PREFIX = '/^\][A-Za-z][0-9A-Za-z]/';

    public static function parse(string $raw, ScaleFormatConfig $format): ?ParsedScaleBarcode
    {
        $code = trim($raw);
        if ($code === '') {
            return null;
        }

        return $format->engine === ScaleBarcodeFormat::ENGINE_GS1
            ? self::parseGs1($code, $format)
            : self::parseFixed($code, $format);
    }

    private static function parseFixed(string $code, ScaleFormatConfig $format): ?ParsedScaleBarcode
    {
        $code = preg_replace(self::AIM_PREFIX, '', $code) ?? $code;

        $numericOnly = in_array($format->symbology, ScaleBarcodeFormat::MOD10_SYMBOLOGIES, true);
        if ($numericOnly && preg_match('/^\d+$/', $code) !== 1) {
            return null;
        }
        if ($format->totalLength !== null && strlen($code) !== $format->totalLength) {
            return null;
        }
        if ($format->prefixes !== [] && ! self::startsWithAny($code, $format->prefixes)) {
            return null;
        }
        if ($format->verifyCheckDigit && $numericOnly && ! CheckDigit::isValidMod10($code)) {
            return null;
        }
        if ($format->pluStart === null || $format->pluLength === null || $format->valueStart === null || $format->valueLength === null) {
            return null;
        }

        $plu = self::slice($code, $format->pluStart, $format->pluLength);
        $valueDigits = self::slice($code, $format->valueStart, $format->valueLength);
        if ($plu === null || $valueDigits === null || preg_match('/^\d+$/', $plu) !== 1 || preg_match('/^\d+$/', $valueDigits) !== 1) {
            return null;
        }

        if ($format->priceCheckDigitPosition !== null) {
            $printed = self::slice($code, $format->priceCheckDigitPosition, 1);
            if ($printed === null || CheckDigit::price($valueDigits) !== $printed) {
                return null;
            }
        }

        return new ParsedScaleBarcode(
            plu: $plu,
            gtin: null,
            valueType: $format->valueType,
            value: self::scale($valueDigits, $format->decimals, $format->multiplier),
            currencyIsoNumeric: null,
            rawValue: $valueDigits,
        );
    }

    private static function parseGs1(string $code, ScaleFormatConfig $format): ?ParsedScaleBarcode
    {
        $fields = Gs1Reader::read($code);
        if ($fields === null) {
            return null;
        }

        $gtin = $fields['01'] ?? null;
        if ($gtin !== null && $format->verifyCheckDigit && ! CheckDigit::isValidMod10($gtin)) {
            return null;
        }
        if ($gtin !== null && $format->prefixes !== [] && ! self::startsWithAny($gtin, $format->prefixes)) {
            return null;
        }

        $plu = null;
        if ($gtin !== null && $format->pluStart !== null && $format->pluLength !== null) {
            $plu = self::slice($gtin, $format->pluStart, $format->pluLength);
        }
        if ($gtin === null && $plu === null) {
            return null;
        }

        // A GS1 label can carry several values; read them all (first of each kind wins).
        $weight = $weightRaw = $weightUnit = null;
        /** @var array<string, string> $weights unit => weight, first of each unit */
        $weights = [];
        $price = $priceRaw = $currency = null;
        $count = $countRaw = null;
        foreach ($fields as $ai => $data) {
            $ai = (string) $ai;
            $decimals = strlen($ai) === 4 ? (int) $ai[3] : 0;
            if (str_starts_with($ai, '310') || str_starts_with($ai, '320')) {
                // 310n = net weight in kg, 320n = net weight in pounds; n = decimals.
                $unit = str_starts_with($ai, '310') ? ParsedScaleBarcode::UNIT_KG : ParsedScaleBarcode::UNIT_LB;
                $weights[$unit] ??= self::scale($data, $decimals, '1');
                if ($weight === null) {
                    [$weight, $weightRaw, $weightUnit] = [$weights[$unit], $data, $unit];
                }
            } elseif ($price === null && str_starts_with($ai, '392')) {
                [$price, $priceRaw] = [self::scale($data, $decimals, '1'), $data];
            } elseif ($price === null && str_starts_with($ai, '393') && strlen($data) > 3) {
                $currency = substr($data, 0, 3);
                [$price, $priceRaw] = [self::scale(substr($data, 3), $decimals, '1'), substr($data, 3)];
            } elseif ($count === null && ($ai === '30' || $ai === '37')) {
                [$count, $countRaw] = [self::scale($data, 0, '1'), $data];
            }
        }

        [$value, $rawValue] = match ($format->valueType) {
            ScaleBarcodeFormat::TYPE_WEIGHT => [$weight, $weightRaw],
            ScaleBarcodeFormat::TYPE_PRICE => [$price, $priceRaw],
            default => [$count, $countRaw],
        };
        if ($value === null || $rawValue === null) {
            return null;
        }

        return new ParsedScaleBarcode(
            plu: $plu,
            gtin: $gtin,
            valueType: $format->valueType,
            value: $value,
            currencyIsoNumeric: $currency,
            rawValue: $rawValue,
            weight: $weight,
            weightUnit: $weightUnit,
            weights: $weights,
            price: $price,
            lot: isset($fields['10']) ? trim($fields['10']) : null,
            expiry: isset($fields['17']) ? self::gs1Date($fields['17']) : null,
            bestBefore: isset($fields['15']) ? self::gs1Date($fields['15']) : null,
        );
    }

    /**
     * GS1 date YYMMDD → Y-m-d. Day 00 means the last day of the month; the century is the one that
     * puts the year closest to today (GS1 rule: up to 50 years ahead, otherwise the past).
     */
    public static function gs1Date(string $yymmdd, ?int $currentYear = null): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m) !== 1) {
            return null;
        }
        [$yy, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($month < 1 || $month > 12) {
            return null;
        }
        $current = $currentYear ?? (int) date('Y');
        $century = intdiv($current, 100) * 100;
        $year = $century + $yy;
        if ($year - $current > 50) {
            $year -= 100;
        } elseif ($current - $year > 49) {
            $year += 100;
        }
        $lastDay = (int) date('t', (int) mktime(0, 0, 0, $month, 1, $year));
        if ($day === 0) {
            $day = $lastDay;
        }
        if ($day < 1 || $day > $lastDay) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** digits ÷ 10^decimals × multiplier, as an exact decimal string. */
    public static function scale(string $digits, int $decimals, string $multiplier): string
    {
        $value = Decimal::div($digits, bcpow('10', (string) max(0, $decimals)));

        return Decimal::round(Decimal::mul($value, $multiplier), 6);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWithAny(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** 1-based substring; null when it runs past the end. */
    private static function slice(string $code, int $start, int $length): ?string
    {
        if ($start < 1 || $length < 1 || $start - 1 + $length > strlen($code)) {
            return null;
        }

        return substr($code, $start - 1, $length);
    }
}
