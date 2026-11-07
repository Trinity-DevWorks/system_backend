<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Date of a currency revaluation and optional rates per currency id (1 primary = rate × currency).
 */
class CurrencyRevaluationRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'rates' => ['sometimes', 'array'],
            'rates.*' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function rates(): array
    {
        $rates = $this->validated('rates');

        return is_array($rates) ? $rates : [];
    }
}
