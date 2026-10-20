<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('quotation_date')) {
            $this->merge(['quotation_date' => now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'salesman_id' => ['nullable', 'uuid', 'exists:salesmen,id'],
            'payment_terms_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'quotation_date' => ['sometimes', 'date'],
            'valid_until' => ['nullable', 'date'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array'],
            ...self::lineRules('lines.*'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lineRules(string $prefix): array
    {
        return [
            $prefix.'.item_id' => ['required', 'uuid', 'exists:items,id'],
            $prefix.'.quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            $prefix.'.item_uom_id' => ['nullable', 'integer', Rule::exists('item_uoms', 'id')],
            $prefix.'.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.9999'],
            $prefix.'.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            $prefix.'.description' => ['nullable', 'string', 'max:500'],
            $prefix.'.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
