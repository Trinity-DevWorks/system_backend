<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;

/**
 * Document exchange rate: 1 primary currency = rate × document currency.
 * Amount in document currency = amount in primary currency × rate.
 */
final class ExchangeRateSnapshot
{
    public const SCALE = 12;

    public static function resolve(
        ExchangeRateService $exchangeRateService,
        int $currencyId,
        mixed $provided,
        string $codePrefix,
    ): string {
        if ($currencyId <= 0) {
            abort(422, 'A currency is required.', ['X-Error-Code' => $codePrefix.'_CURRENCY_REQUIRED']);
        }

        $primary = Currency::getPrimary();
        if ($primary === null) {
            abort(422, 'A primary currency is required.', ['X-Error-Code' => $codePrefix.'_PRIMARY_CURRENCY_REQUIRED']);
        }

        if ($currencyId === (int) $primary->id) {
            return self::format(1.0);
        }

        if ($provided !== null && $provided !== '') {
            $rate = (float) $provided;
            if ($rate <= 0) {
                abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => $codePrefix.'_EXCHANGE_RATE_INVALID']);
            }

            return self::format($rate);
        }

        try {
            $rate = $exchangeRateService->getRateById((int) $primary->id, $currencyId);
        } catch (\InvalidArgumentException) {
            abort(422, 'Enter an exchange rate for this currency.', ['X-Error-Code' => $codePrefix.'_EXCHANGE_RATE_REQUIRED']);
        }

        if ($rate <= 0) {
            abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => $codePrefix.'_EXCHANGE_RATE_INVALID']);
        }

        return self::format($rate);
    }

    /**
     * Convert a document-currency amount back to the primary currency.
     */
    public static function toPrimary(string $amount, string $rate, int $scale): string
    {
        if (bccomp($rate, '0', self::SCALE) <= 0) {
            return bcadd($amount, '0', $scale);
        }

        return bcdiv($amount, $rate, $scale);
    }

    private static function format(float $rate): string
    {
        return number_format($rate, self::SCALE, '.', '');
    }
}
