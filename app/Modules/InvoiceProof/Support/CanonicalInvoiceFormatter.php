<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use JsonException;

/**
 * Formats values for canonical JSON so the same invoice always encodes the same way:
 * fixed decimal scales, UTC dates, empty strings as null, and stable json_encode flags.
 */
final class CanonicalInvoiceFormatter
{
    public static function money(mixed $value): string
    {
        return self::decimal($value, CanonicalInvoiceSchema::MONEY_SCALE);
    }

    public static function quantity(mixed $value): string
    {
        return self::decimal($value, CanonicalInvoiceSchema::QUANTITY_SCALE);
    }

    public static function rate(mixed $value): string
    {
        return self::decimal($value, CanonicalInvoiceSchema::RATE_SCALE);
    }

    public static function percent(mixed $value): string
    {
        return self::decimal($value, CanonicalInvoiceSchema::PERCENT_SCALE);
    }

    public static function decimal(mixed $value, int $scale): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', $scale);
        }

        $normalized = self::normalizeNumberString((string) $value);

        return bcadd($normalized, '0', $scale);
    }

    public static function date(mixed $value): ?string
    {
        $carbon = self::parseCarbon($value);

        return $carbon?->utc()->format(CanonicalInvoiceSchema::DATE_FORMAT);
    }

    public static function dateTimeUtc(mixed $value): ?string
    {
        $carbon = self::parseCarbon($value);

        return $carbon?->utc()->format(CanonicalInvoiceSchema::DATETIME_FORMAT);
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public static function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private static function normalizeNumberString(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '-' || $value === '+' || $value === '.') {
            return '0';
        }

        return $value;
    }

    private static function parseCarbon(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return Carbon::parse((string) $value);
    }
}
