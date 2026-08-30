<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Http\Requests;

use App\Modules\CompanySetting\Enums\DateFormat;
use App\Modules\CompanySetting\Enums\NumberFormat;
use App\Modules\CompanySetting\Enums\PreferredLanguage;
use App\Modules\CompanySetting\Enums\PriceRoundingMode;
use App\Modules\CompanySetting\Enums\TaxPriceMode;
use App\Modules\CompanySetting\Support\CountryCatalog;
use App\Modules\Inventory\Stock\Enums\InventoryCostingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompanySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('country') || ! is_string($this->input('country'))) {
            return;
        }

        $this->merge([
            'country' => CountryCatalog::normalize((string) $this->input('country')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(CountryCatalog::codes())],
            'preferred_language' => ['sometimes', 'required', Rule::enum(PreferredLanguage::class)],
            'timezone' => ['sometimes', 'required', 'string', 'max:64'],
            'date_format' => ['sometimes', 'required', Rule::enum(DateFormat::class)],
            'number_format' => ['sometimes', 'required', Rule::enum(NumberFormat::class)],
            'tax_enabled' => ['sometimes', 'required', 'boolean'],
            'tax_price_mode' => ['sometimes', 'required', Rule::enum(TaxPriceMode::class)],
            'allow_negative_stock' => ['sometimes', 'required', 'boolean'],
            'inventory_costing_method' => ['sometimes', 'required', Rule::enum(InventoryCostingMethod::class)],
            'price_rounding_mode' => ['sometimes', 'required', Rule::enum(PriceRoundingMode::class)],
            'price_decimal_places' => ['sometimes', 'required', 'integer', 'min:0', 'max:6'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('timezone')) {
                return;
            }

            $timezone = (string) $this->input('timezone');
            if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
                $validator->errors()->add('timezone', 'The selected timezone is invalid.');
            }
        });
    }
}
