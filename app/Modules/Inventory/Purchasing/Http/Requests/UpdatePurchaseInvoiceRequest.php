<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseInvoiceRequest extends FormRequest
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
            'supplier_id' => ['sometimes', 'uuid', 'exists:suppliers,id'],
            'goods_receipt_id' => ['nullable', 'uuid', 'exists:goods_receipts,id'],
            'purchase_order_id' => ['nullable', 'uuid', 'exists:purchase_orders,id'],
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'payment_terms_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'invoice_date' => ['sometimes', 'date'],
            'due_on' => ['nullable', 'date'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reference_2' => ['nullable', 'string', 'max:128'],
            'linked_proof_id' => ['nullable', 'uuid'],
            'disclosure' => ['nullable', 'array'],
            'disclosure.proof_id' => ['required_with:disclosure', 'uuid'],
            'disclosure.content_hash' => ['required_with:disclosure', 'string'],
            'disclosure.fields' => ['required_with:disclosure', 'array', 'min:1'],
            'disclosure.fields.*.path' => ['required', 'string'],
            'disclosure.fields.*.value' => ['nullable'],
            'disclosure.fields.*.salt' => ['required', 'string'],
            'disclosure.fields.*.proof' => ['required', 'array'],
            'adjustment' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
