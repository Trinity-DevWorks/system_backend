<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductionRequest extends FormRequest
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
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'production_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'lot_number' => ['nullable', 'string', 'max:64'],
            'expiry_date' => ['nullable', 'date'],
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
            'lines.*.theoretical_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.item_uom_id' => ['nullable', 'integer', 'exists:item_uoms,id'],
            'lines.*.lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:64'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
