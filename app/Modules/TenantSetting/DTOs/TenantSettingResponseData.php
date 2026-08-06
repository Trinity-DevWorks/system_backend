<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\DTOs;

use App\Modules\Currency\Models\Currency;
use App\Modules\TenantSetting\Models\TenantSetting;

readonly class TenantSettingResponseData
{
    /**
     * @param  array{id: int, code: string, name: string, symbol: string}|null  $primaryCurrency
     */
    public function __construct(
        public int $id,
        public ?int $primaryCurrencyId,
        public ?array $primaryCurrency,
        public ?string $country,
        public string $preferredLanguage,
        public string $timezone,
        public string $dateFormat,
        public string $numberFormat,
        public bool $taxEnabled,
        public bool $allowNegativeStock,
        public string $priceRoundingMode,
        public int $priceDecimalPlaces,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(TenantSetting $settings): self
    {
        $settings->loadMissing('primaryCurrency');

        return new self(
            id: (int) $settings->id,
            primaryCurrencyId: $settings->primary_currency_id !== null ? (int) $settings->primary_currency_id : null,
            primaryCurrency: self::currencyBrief($settings->primaryCurrency),
            country: $settings->country,
            preferredLanguage: $settings->preferred_language->value,
            timezone: $settings->timezone,
            dateFormat: $settings->date_format->value,
            numberFormat: $settings->number_format->value,
            taxEnabled: (bool) $settings->tax_enabled,
            allowNegativeStock: (bool) $settings->allow_negative_stock,
            priceRoundingMode: $settings->price_rounding_mode->value,
            priceDecimalPlaces: (int) $settings->price_decimal_places,
            createdAt: (string) $settings->created_at,
            updatedAt: (string) $settings->updated_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'primary_currency_id' => $this->primaryCurrencyId,
            'primary_currency' => $this->primaryCurrency,
            'country' => $this->country,
            'preferred_language' => $this->preferredLanguage,
            'timezone' => $this->timezone,
            'date_format' => $this->dateFormat,
            'number_format' => $this->numberFormat,
            'tax_enabled' => $this->taxEnabled,
            'allow_negative_stock' => $this->allowNegativeStock,
            'price_rounding_mode' => $this->priceRoundingMode,
            'price_decimal_places' => $this->priceDecimalPlaces,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array{id: int, code: string, name: string, symbol: string}|null
     */
    private static function currencyBrief(?Currency $currency): ?array
    {
        if ($currency === null) {
            return null;
        }

        return [
            'id' => (int) $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
        ];
    }
}
