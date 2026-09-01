<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
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
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'payment_terms_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'purchase_order_id' => ['nullable', 'uuid', 'exists:purchase_orders,id'],
            'goods_receipt_id' => ['nullable', 'uuid', 'exists:goods_receipts,id'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array'],
            ...self::lineRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lineRules(): array
    {
        return [
            'lines.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'lines.*.item_uom_id' => ['nullable', 'integer', 'exists:item_uoms,id'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.9999'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer', 'exists:goods_receipt_lines,id'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
