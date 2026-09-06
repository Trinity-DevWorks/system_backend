<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncSalesInvoiceLinesRequest extends FormRequest
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
