<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Display-only date/number formatting from company settings (PDF, mail).
 * Does not change stored ISO dates or canonical decimals.
 */
final class TenantDisplayFormat
{
    public static function date(mixed $value, ?CompanySetting $settings = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $format = ($settings ?? self::settings())->date_format->value;

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = Carbon::createFromFormat('Y-m-d', $value);

            return $parsed !== false ? $parsed->format($format) : '';
        }

        if ($value instanceof CarbonInterface) {
            return $value->format($format);
        }

        try {
            return Carbon::parse((string) $value)->format($format);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function dateTime(mixed $value, ?CompanySetting $settings = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $settings ??= self::settings();
        $pattern = $settings->date_format->value.' H:i';

        try {
            $parsed = $value instanceof CarbonInterface
                ? $value
                : Carbon::parse((string) $value);

            return $parsed->timezone($settings->timezone)->format($pattern);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function number(
        mixed $value,
        ?int $decimals = null,
        bool $trimTrailingZeros = false,
        ?CompanySetting $settings = null,
    ): string {
        if ($value === null || $value === '') {
            return '';
        }

        $n = is_numeric($value) ? (float) $value : null;
        if ($n === null || is_nan($n) || is_infinite($n)) {
            return '';
        }

        $settings ??= self::settings();
        $places = $decimals ?? (int) $settings->price_decimal_places;
        $places = max(0, min(6, $places));
        [$thousands, $decimal] = $settings->number_format->separators();

        $negative = $n < 0;
        $body = number_format(abs($n), $places, $decimal, $thousands);

        if ($trimTrailingZeros && $places > 0) {
            $body = rtrim($body, '0');
            $body = rtrim($body, $decimal);
        }

        return $negative ? '-'.$body : $body;
    }

    public static function money(mixed $value, ?CompanySetting $settings = null): string
    {
        $settings ??= self::settings();

        return self::number($value, (int) $settings->price_decimal_places, false, $settings);
    }

    public static function quantity(mixed $value, ?CompanySetting $settings = null): string
    {
        return self::number($value, 6, true, $settings ?? self::settings());
    }

    /**
     * Format mail/notification placeholders. Stored payload stays canonical.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function notificationParams(array $params, ?CompanySetting $settings = null): array
    {
        $settings ??= self::settingsOrNull();
        if ($settings === null) {
            return $params;
        }

        $out = [];
        foreach ($params as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                $out[$key] = $value;

                continue;
            }

            if ($key === 'expiry_date' || str_ends_with($key, '_date')) {
                $formatted = self::date($value, $settings);
                $out[$key] = $formatted !== '' ? $formatted : $value;

                continue;
            }

            if ($key === 'on_hand_qty' || str_ends_with($key, '_qty') || str_ends_with($key, '_quantity')) {
                $formatted = self::quantity($value, $settings);
                $out[$key] = $formatted !== '' ? $formatted : $value;

                continue;
            }

            if (
                str_ends_with($key, '_price')
                || str_ends_with($key, '_amount')
                || $key === 'unit_cost'
                || $key === 'amount'
            ) {
                $formatted = self::money($value, $settings);
                $out[$key] = $formatted !== '' ? $formatted : $value;

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private static function settings(): CompanySetting
    {
        return CompanySetting::current();
    }

    private static function settingsOrNull(): ?CompanySetting
    {
        try {
            if (function_exists('tenant') && tenant() === null) {
                return null;
            }

            return CompanySetting::current();
        } catch (\Throwable) {
            return null;
        }
    }
}
