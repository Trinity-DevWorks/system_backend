<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\DTOs;

use App\Modules\TenantSetting\Enums\DateFormat;
use App\Modules\TenantSetting\Enums\NumberFormat;
use App\Modules\TenantSetting\Enums\PreferredLanguage;
use App\Modules\TenantSetting\Enums\PriceRoundingMode;
use App\Modules\TenantSetting\Http\Requests\UpdateTenantSettingRequest;
use App\Modules\TenantSetting\Models\TenantSetting;

readonly class TenantSettingData
{
    public function __construct(
        public ?string $country,
        public PreferredLanguage $preferredLanguage,
        public string $timezone,
        public DateFormat $dateFormat,
        public NumberFormat $numberFormat,
        public bool $taxEnabled,
        public bool $allowNegativeStock,
        public PriceRoundingMode $priceRoundingMode,
        public int $priceDecimalPlaces,
    ) {}

    public static function fromUpdateRequest(UpdateTenantSettingRequest $request, TenantSetting $settings): self
    {
        $data = $request->validated();

        return new self(
            country: array_key_exists('country', $data)
                ? self::nullableCountry($data['country'])
                : $settings->country,
            preferredLanguage: array_key_exists('preferred_language', $data)
                ? PreferredLanguage::from((string) $data['preferred_language'])
                : $settings->preferred_language,
            timezone: array_key_exists('timezone', $data)
                ? (string) $data['timezone']
                : $settings->timezone,
            dateFormat: array_key_exists('date_format', $data)
                ? DateFormat::from((string) $data['date_format'])
                : $settings->date_format,
            numberFormat: array_key_exists('number_format', $data)
                ? NumberFormat::from((string) $data['number_format'])
                : $settings->number_format,
            taxEnabled: array_key_exists('tax_enabled', $data)
                ? (bool) $data['tax_enabled']
                : (bool) $settings->tax_enabled,
            allowNegativeStock: array_key_exists('allow_negative_stock', $data)
                ? (bool) $data['allow_negative_stock']
                : (bool) $settings->allow_negative_stock,
            priceRoundingMode: array_key_exists('price_rounding_mode', $data)
                ? PriceRoundingMode::from((string) $data['price_rounding_mode'])
                : $settings->price_rounding_mode,
            priceDecimalPlaces: array_key_exists('price_decimal_places', $data)
                ? (int) $data['price_decimal_places']
                : (int) $settings->price_decimal_places,
        );
    }

    /**
     * @return array{
     *     country: ?string,
     *     preferred_language: string,
     *     timezone: string,
     *     date_format: string,
     *     number_format: string,
     *     tax_enabled: bool,
     *     allow_negative_stock: bool,
     *     price_rounding_mode: string,
     *     price_decimal_places: int
     * }
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'preferred_language' => $this->preferredLanguage->value,
            'timezone' => $this->timezone,
            'date_format' => $this->dateFormat->value,
            'number_format' => $this->numberFormat->value,
            'tax_enabled' => $this->taxEnabled,
            'allow_negative_stock' => $this->allowNegativeStock,
            'price_rounding_mode' => $this->priceRoundingMode->value,
            'price_decimal_places' => $this->priceDecimalPlaces,
        ];
    }

    private static function nullableCountry(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strtoupper((string) $value);
    }
}
