<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Http\Requests;

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
            'supplier_id' => ['sometimes', 'required', 'uuid', 'exists:suppliers,id'],
            'currency_id' => ['sometimes', 'required', 'integer', 'exists:currencies,id'],
            'payment_terms_id' => ['sometimes', 'nullable', 'integer', 'exists:payment_terms,id'],
            'payment_method_id' => ['sometimes', 'nullable', 'integer', 'exists:payment_methods,id'],
            'purchase_order_id' => ['sometimes', 'nullable', 'uuid', 'exists:purchase_orders,id'],
            'goods_receipt_id' => ['sometimes', 'nullable', 'uuid', 'exists:goods_receipts,id'],
            'invoice_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
