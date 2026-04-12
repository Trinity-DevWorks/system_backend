<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetCurrencyRateHistoryRequest extends FormRequest
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
