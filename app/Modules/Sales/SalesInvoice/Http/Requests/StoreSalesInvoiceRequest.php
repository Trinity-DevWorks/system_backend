<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('invoice_date')) {
            $this->merge(['invoice_date' => now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
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
            'lines' => ['sometimes', 'array'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'lines.*.item_uom_id' => ['nullable', 'integer', Rule::exists('item_uoms', 'id')],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.9999'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
