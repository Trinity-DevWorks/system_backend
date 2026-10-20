<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncPurchaseDebitNoteLinesRequest extends FormRequest
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
            'lines' => ['present', 'array'],
            'lines.*.purchase_invoice_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
