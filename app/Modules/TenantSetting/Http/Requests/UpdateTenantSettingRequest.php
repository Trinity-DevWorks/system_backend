<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Http\Requests;

use App\Modules\TenantSetting\Enums\DateFormat;
use App\Modules\TenantSetting\Enums\NumberFormat;
use App\Modules\TenantSetting\Enums\PreferredLanguage;
use App\Modules\TenantSetting\Enums\PriceRoundingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTenantSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'preferred_language' => ['sometimes', 'required', Rule::enum(PreferredLanguage::class)],
            'timezone' => ['sometimes', 'required', 'string', 'max:64'],
            'date_format' => ['sometimes', 'required', Rule::enum(DateFormat::class)],
            'number_format' => ['sometimes', 'required', Rule::enum(NumberFormat::class)],
            'tax_enabled' => ['sometimes', 'required', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'required', 'boolean'],
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
