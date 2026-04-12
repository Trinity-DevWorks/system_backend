<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCurrencyRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:10', 'unique:currencies,code'],
            'iso_code' => ['required', 'string', 'max:10', 'unique:currencies,iso_code'],
            'symbol' => ['nullable', 'string', 'max:16'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'from_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'to_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'smallest_unit' => ['nullable', 'numeric', 'min:0'],
            'round_limit' => ['nullable', 'numeric', 'min:0'],
            'acceptable_amount_overdue' => ['nullable', 'numeric', 'min:0'],
            'allowed_difference_in_receipt' => ['nullable', 'numeric', 'min:0'],
            'allowed_difference_in_payment' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'is_primary' => ['nullable', 'boolean'],
        ];

        if (! $this->boolean('is_primary') && $this->filled('rate') && is_numeric($this->input('rate')) && (float) $this->input('rate') > 0) {
            $rules['from_currency_id'] = ['required', 'integer', 'exists:currencies,id'];
        }

        return $rules;
    }
}
