<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesOrderRequest extends FormRequest
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
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'salesman_id' => ['nullable', 'uuid', 'exists:salesmen,id'],
            'payment_terms_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'order_date' => ['sometimes', 'date'],
            'requested_date' => ['nullable', 'date'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
