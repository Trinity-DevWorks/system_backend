<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDeliveryNoteRequest extends FormRequest
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
        $required = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'customer_id' => [$required, 'uuid', Rule::exists('customers', 'id')],
            'warehouse_id' => [$required, 'integer', Rule::exists('warehouses', 'id')],
            'sales_order_id' => ['nullable', 'uuid', Rule::exists('sales_orders', 'id')],
            'delivery_date' => ['sometimes', 'date'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => [$required, 'array', 'max:500'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer', Rule::exists('sales_order_lines', 'id')],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')],
            'lines.*.item_uom_id' => ['nullable', 'integer'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
            'lines.*.lot_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
