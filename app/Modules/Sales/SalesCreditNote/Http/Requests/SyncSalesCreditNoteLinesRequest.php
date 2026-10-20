<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSalesCreditNoteLinesRequest extends FormRequest
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
            'lines.*.sales_invoice_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.restock' => ['sometimes', 'boolean'],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
