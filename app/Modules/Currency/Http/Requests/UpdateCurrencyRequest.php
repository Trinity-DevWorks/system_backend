<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurrencyRequest extends FormRequest
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
        $currency = $this->route('currency');
        $currencyId = is_object($currency) ? $currency->id : $currency;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:10', Rule::unique('currencies', 'code')->ignore($currencyId)],
            'iso_code' => ['sometimes', 'string', 'max:10', Rule::unique('currencies', 'iso_code')->ignore($currencyId)],
            'symbol' => ['sometimes', 'nullable', 'string', 'max:16'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'from_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'to_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'smallest_unit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'round_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'acceptable_amount_overdue' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'allowed_difference_in_receipt' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'allowed_difference_in_payment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
