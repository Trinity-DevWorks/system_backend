<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use App\Modules\CompanySetting\Enums\DateFormat;
use App\Modules\CompanySetting\Enums\NumberFormat;
use App\Modules\CompanySetting\Enums\PreferredLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePlatformSettingRequest extends FormRequest
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
            'preferred_language' => ['sometimes', 'required', Rule::enum(PreferredLanguage::class)],
            'timezone' => ['sometimes', 'required', 'string', 'max:64'],
            'date_format' => ['sometimes', 'required', Rule::enum(DateFormat::class)],
            'number_format' => ['sometimes', 'required', Rule::enum(NumberFormat::class)],
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
