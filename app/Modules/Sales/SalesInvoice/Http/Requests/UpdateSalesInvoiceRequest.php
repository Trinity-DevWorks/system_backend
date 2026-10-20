<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesInvoiceRequest extends FormRequest
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
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'payment_terms_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'invoice_date' => ['sometimes', 'date'],
            'due_on' => ['nullable', 'date'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reference_2' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'billing_address.address_line_1' => ['nullable', 'string', 'max:255'],
            'billing_address.address_line_2' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['nullable', 'string', 'max:128'],
            'billing_address.state' => ['nullable', 'string', 'max:128'],
            'billing_address.country' => ['nullable', 'string', 'max:100'],
            'billing_address.phone' => ['nullable', 'string', 'max:32'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'shipping_address.address_line_1' => ['nullable', 'string', 'max:255'],
            'shipping_address.address_line_2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:128'],
            'shipping_address.state' => ['nullable', 'string', 'max:128'],
            'shipping_address.country' => ['nullable', 'string', 'max:100'],
            'shipping_address.phone' => ['nullable', 'string', 'max:32'],
            'adjustment' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
