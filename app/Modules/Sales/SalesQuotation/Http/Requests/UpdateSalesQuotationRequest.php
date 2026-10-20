<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesQuotationRequest extends FormRequest
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
            'customer_id' => ['sometimes', 'uuid', 'exists:customers,id'],
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'salesman_id' => ['nullable', 'uuid', 'exists:salesmen,id'],
            'payment_terms_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'quotation_date' => ['sometimes', 'date'],
            'valid_until' => ['nullable', 'date'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
